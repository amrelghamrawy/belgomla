<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Jobs\ProcessProviderWebhook;
use App\Modules\Messaging\Queries\OutboundMessageByProviderIdQuery;
use App\Modules\Messaging\Services\Hypersender\HypersenderErrors;
use App\Support\ApiException;

/**
 * Takes a verified Hypersender webhook (08-api-contract.md §13). Events that are not delivery statuses
 * (heartbeats, incoming messages) are acknowledged and dropped. A status for a message we never sent is
 * `404 not_found`. Anything else is queued for RecordDeliveryStatus.
 */
final readonly class AcceptHypersenderWebhook
{
    public function __construct(private HypersenderErrors $errors, private OutboundMessageByProviderIdQuery $messages) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function handle(Channel $channel, array $payload): void
    {
        $reference = $this->errors->webhookReference($channel, $payload);

        if ($reference === null) {
            return;
        }

        $message = $this->messages->find($channel, $reference['message_id'], $reference['request_id'])
            ?? throw ApiException::notFound();

        ProcessProviderWebhook::dispatch($message->id, $channel, $payload);
    }
}
