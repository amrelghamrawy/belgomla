<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use RuntimeException;

/**
 * BR-C03: stores the order's collected amount (Σ non-voided collections, summed by Collections) and the collection
 * status derived from it. A step of RecordCollection / VoidCollection: it joins their transaction, with the order
 * row already locked. Collections rejects over-collection first (`exceeds_balance`), so more than the total here
 * is a bug, not a user error.
 */
final readonly class ApplyCollectedAmount
{
    public function handle(Order $order, string $collected): void
    {
        if (Money::compare($collected, '0.00') < 0 || Money::compare($collected, $order->order_total) > 0) {
            throw new RuntimeException("Collected amount {$collected} is outside 0..{$order->order_total} on order {$order->id}.");
        }

        $order->forceFill([
            'collected_amount' => $collected,
            'collection_status' => CollectionStatus::for($collected, $order->order_total),
        ])->save();
    }
}
