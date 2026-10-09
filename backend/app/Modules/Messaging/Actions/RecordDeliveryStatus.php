<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\DriverResult;
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
 * `fell_back` and an SMS copy is queued (BR-CU07); the unique `fallback_of_id` makes a second copy impossible.
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
            $locked->status = $this->statusFor($locked, $result);

            if ($locked->status === MessageStatus::FellBack) {
                $this->queueSmsCopy($locked);
            }

            // An SMS never falls back, so its text is not needed once the phone has sent it.
            if ($locked->status->isFinal() || ($locked->status === MessageStatus::Sent && $locked->channel === Channel::Sms)) {
                $locked->body = null;
            }

            $locked->save();

            return $locked;
        });
    }

    private function statusFor(OutboundMessage $message, DriverResult $result): MessageStatus
    {
        return match (true) {
            ! $result->ok => $this->fallback->shouldFallBack($message, $result) ? MessageStatus::FellBack : MessageStatus::Failed,
            $result->delivered => MessageStatus::Delivered,
            $result->final => MessageStatus::Sent,
            default => MessageStatus::Queued,
        };
    }

    private function queueSmsCopy(OutboundMessage $failed): void
    {
        $sms = OutboundMessage::query()->create([
            'uuid' => (string) Str::uuid(),
            'to_phone' => $failed->to_phone,
            'purpose' => $failed->purpose,
            'channel' => Channel::Sms,
            'provider' => $failed->provider,
            'status' => MessageStatus::Queued,
            'body' => $failed->body,
            'fallback_of_id' => $failed->id,
        ]);

        SendMessage::dispatch($sms->id)->afterCommit();
    }
}
