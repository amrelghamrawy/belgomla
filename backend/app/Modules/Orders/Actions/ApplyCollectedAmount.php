<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use RuntimeException;

/**
 * BR-C03: stores the order's collected amount (Σ live payments − Σ live refunds, summed by Collections) and the
 * collection status derived from it against the **net** total (order_total − returned_total, BR-O08). A step of
 * RecordCollection / VoidCollection / RecordReturn / VoidReturn: it joins their transaction, with the order row
 * already locked. Collections rejects over-collection first (`exceeds_balance`); the one way above the net total is
 * voiding a return's refund before the return itself (BR-R06), so only a negative amount or one above the gross
 * total is a bug here, not a user error.
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
            'collection_status' => CollectionStatus::for($collected, $order->netTotal()),
        ])->save();
    }
}
