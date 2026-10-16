<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\RecordDeliveryStatus;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\MessageContext;
use App\Modules\Messaging\Services\MessageDrivers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hypersender v2 is asynchronous: re-checks a queued request after `messaging.poll.delays` seconds until the
 * provider has a final result. After the last poll the message fails with `poll_timeout` (a WhatsApp message
 * then falls back to SMS, BR-CU07). A webhook that arrives first turns the remaining polls into no-ops.
 */
final class PollMessageStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $messageId, public readonly int $attempt)
    {
        $this->onQueue('messaging');
    }

    public static function schedule(int $messageId, int $attempt): void
    {
        self::dispatch($messageId, $attempt)->delay(self::delayBefore($attempt))->afterCommit();
    }

    public static function maxAttempts(): int
    {
        return count(config()->array('messaging.poll.delays'));
    }

    public static function delayBefore(int $attempt): int
    {
        $delays = array_values(config()->array('messaging.poll.delays'));
        $delay = $delays[$attempt - 1] ?? 60;

        return is_int($delay) ? $delay : 60;
    }

    public function handle(MessageContext $context, MessageDrivers $drivers, RecordDeliveryStatus $record): void
    {
        $context->run($this->messageId, function (OutboundMessage $message) use ($drivers, $record): void {
            if ($message->status->isFinal()) {
                return;
            }

            $result = $drivers->forMessage($message)->status($message);

            if (! $result->final && $this->attempt >= self::maxAttempts()) {
                $result = DriverResult::failed('poll_timeout', providerRequestId: $message->provider_request_id);
            }

            $record->handle($message, $result);

            if (! $result->final) {
                self::schedule($message->id, $this->attempt + 1);
            }
        });
    }

    /** The status endpoint kept failing; the message cannot be confirmed. */
    public function failed(?Throwable $exception): void
    {
        app(MessageContext::class)->run($this->messageId, function (OutboundMessage $message): void {
            app(RecordDeliveryStatus::class)->handle($message, DriverResult::failed('provider_unreachable'));
        });
    }
}
