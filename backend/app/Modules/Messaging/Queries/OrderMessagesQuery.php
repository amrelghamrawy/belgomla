<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Queries;

use App\Modules\Messaging\Models\OutboundMessage;

/**
 * BR-N03: the messages sent about one order, oldest first, fallbacks included, for the dashboard order page. Only the
 * delivery facts leave the module (no body: it is encrypted and cleared once delivered).
 */
final readonly class OrderMessagesQuery
{
    /**
     * @return list<array{purpose: string, purpose_label: string, channel: string, status: string, sent_at: string}>
     */
    public function forOrder(int $orderId): array
    {
        return array_values(OutboundMessage::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->map(fn (OutboundMessage $message): array => [
                'purpose' => $message->purpose->value,
                'purpose_label' => $message->purpose->label(),
                'channel' => $message->channel->value,
                'status' => $message->status->value,
                'sent_at' => $message->created_at->toIso8601String(),
            ])
            ->all());
    }
}
