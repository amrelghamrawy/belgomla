<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\Scopes\NotVoided;
use App\Support\Money;

/** BR-C03: `collected_amount = Σ non-voided collection amounts` of one order, as "150.00". */
final readonly class CollectedAmountQuery
{
    public function forOrder(int $orderId): string
    {
        $sum = CashCollection::query()->where('order_id', $orderId)->tap(new NotVoided)->sum('amount');

        return Money::add('0.00', (string) $sum);
    }
}
