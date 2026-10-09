<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\RecordDeliveryStatus;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\MessageContext;
use App\Modules\Messaging\Services\MessageDrivers;
use App\Modules\Messaging\Services\MessagingRateLimiter;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands one queued message to its channel's driver (Tech §13). Waits instead of failing when the global
 * provider limit is reached (D46). An accepted-but-unfinished send is polled; a failure goes through
 * RecordDeliveryStatus, which falls back to SMS for WhatsApp (BR-CU07).
 */
final class SendMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('messaging');
    }

    /** Retries (provider down) and rate-limit waits share one window instead of a try count. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(MessageContext $context, MessagingRateLimiter $limiter, MessageDrivers $drivers, RecordDeliveryStatus $record): void
    {
        $context->run($this->messageId, function (OutboundMessage $message) use ($limiter, $drivers, $record): void {
            if ($message->status !== MessageStatus::Queued || $message->provider_request_id !== null || $message->provider_message_id !== null) {
                return;
            }

            $driver = $drivers->for($message->channel);
            $delay = $driver->provider() === Provider::Log ? null : $limiter->providerDelay();

            if ($delay !== null) {
                $this->release($delay);

                return;
            }

            $result = $driver->send($message, MessagePayload::fromMessage($message));
            $record->handle($message, $result);

            if (! $result->final) {
                PollMessageStatus::schedule($message->id, 1);
            }
        });
    }

    /** The provider stayed unreachable for the whole retry window. */
    public function failed(?Throwable $exception): void
    {
        app(MessageContext::class)->run($this->messageId, function (OutboundMessage $message): void {
            app(RecordDeliveryStatus::class)->handle($message, DriverResult::failed('provider_unreachable'));
        });
    }
}
