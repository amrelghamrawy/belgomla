<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\FallbackRoute;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\Fallback;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies what the provider said about a message — from the send call, a poll or a webhook (Tech §13).
 * Owns its transaction and locks the row, so a poll and a webhook racing on the same message are applied one
 * after the other. A final row ignores everything (replays are no-ops). A failed WhatsApp message becomes
 * `fell_back` and a copy is queued on the next channel (BR-CU07, D70: store number → platform number → SMS);
 * the unique `fallback_of_id` makes a second copy impossible.
 * The encrypted body is cleared once the message is final.
 */
final readonly class RecordDeliveryStatus
{
    public function __construct(private Fallback $fallback) {}

    public function handle(OutboundMessage $message, DriverResult $result): OutboundMessage
    {
        return DB::transaction(function () use ($message, $result): OutboundMessage {
            $locked = OutboundMessage::query()->lockForUpdate()->findOrFail($message->id);

            if ($locked->status->isFinal()) {
                return $locked;
            }

            $locked->provider_request_id = $result->providerRequestId ?? $locked->provider_request_id;
            $locked->provider_message_id = $result->providerMessageId ?? $locked->provider_message_id;
            $locked->error_code = $result->errorCode;
            $route = $this->fallback->next($locked, $result);
            $locked->status = $this->statusFor($result, $route);

            if ($route instanceof FallbackRoute) {
                $this->queueCopy($locked, $route);
            }

            // An SMS never falls back, so its text is not needed once the phone has sent it.
            if ($locked->status->isFinal() || ($locked->status === MessageStatus::Sent && $locked->channel === Channel::Sms)) {
                $locked->body = null;
            }

            $locked->save();

            return $locked;
        });
    }

    private function statusFor(DriverResult $result, ?FallbackRoute $route): MessageStatus
    {
        return match (true) {
            ! $result->ok => $route instanceof FallbackRoute ? MessageStatus::FellBack : MessageStatus::Failed,
            $result->delivered => MessageStatus::Delivered,
            $result->final => MessageStatus::Sent,
            default => MessageStatus::Queued,
        };
    }

    private function queueCopy(OutboundMessage $failed, FallbackRoute $route): void
    {
        $copy = OutboundMessage::query()->create([
            'uuid' => (string) Str::uuid(),
            'to_phone' => $failed->to_phone,
            'purpose' => $failed->purpose,
            'channel' => $route->channel,
            'provider' => $route->provider,
            'status' => MessageStatus::Queued,
            'body' => $failed->body,
            'fallback_of_id' => $failed->id,
        ]);

        SendMessage::dispatch($copy->id)->afterCommit();
    }
}
