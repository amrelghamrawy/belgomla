<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\Scopes\NotVoided;
use App\Support\Money;

/** BR-C03: `collected_amount = Σ live payments − Σ live refunds` of one order (BR-R04), as "150.00". */
final readonly class CollectedAmountQuery
{
    public function forOrder(int $orderId): string
    {
        $sum = CashCollection::query()
            ->where('order_id', $orderId)
            ->tap(new NotVoided)
            ->selectRaw('coalesce(sum(case when kind = ? then -amount else amount end), 0) as collected', [CollectionKind::Refund->value])
            ->value('collected');

        return Money::add('0.00', is_numeric($sum) ? (string) $sum : '0');
    }
}
