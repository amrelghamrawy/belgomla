<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Models\Order;

/**
 * BR-C04: "mark delivered and collected the full amount" records one cash collection for the order's remaining
 * balance, inside the transition's transaction. Collections implements it (RemainingBalanceCollector, bound in
 * CollectionsServiceProvider); without it NullCollectsPayments records nothing.
 */
interface CollectsPayments
{
    public function collectRemaining(Order $order, ?int $actorId): void;
}
