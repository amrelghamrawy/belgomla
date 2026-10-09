<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\RecordDeliveryStatus;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\Hypersender\HypersenderErrors;
use App\Modules\Messaging\Services\MessageContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a verified Hypersender status webhook to its message, inside the message's tenant (Tech §13).
 */
final class ProcessProviderWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(public readonly int $messageId, public readonly Channel $channel, public readonly array $payload)
    {
        $this->onQueue('messaging');
    }

    public function handle(MessageContext $context, HypersenderErrors $errors, RecordDeliveryStatus $record): void
    {
        $context->run($this->messageId, function (OutboundMessage $message) use ($errors, $record): void {
            $record->handle($message, $errors->fromWebhook($this->channel, $this->payload));
        });
    }
}
