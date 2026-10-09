<?php

declare(strict_types=1);

namespace App\Modules\Collections\Services;

use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Models\Order;
use App\Support\Money;

/**
 * BR-C04 "delivered and collected the full amount": Orders' CollectsPayments, called by TransitionOrder inside its
 * transaction with the order locked. Records one collection for whatever is left (nothing when the order is
 * already fully collected, e.g. a deposit covered it) and refreshes the order the transition answers with.
 */
final readonly class RemainingBalanceCollector implements CollectsPayments
{
    public function __construct(
        private CollectedAmountQuery $collected,
        private RecordCollection $record,
    ) {}

    public function collectRemaining(Order $order, ?int $actorId): void
    {
        $remaining = Money::subtract($order->order_total, $this->collected->forOrder($order->id));

        if (Money::compare($remaining, '0.00') <= 0) {
            return;
        }

        $this->record->handle($order, new CollectionData($remaining), $actorId);
        $order->refresh();
    }
}
