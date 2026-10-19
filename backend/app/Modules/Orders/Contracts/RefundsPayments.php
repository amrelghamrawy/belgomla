<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Models\Order;

/**
 * BR-R04: a return that leaves an order collected beyond its net total pays the difference back in cash, as a
 * `refund` collection tied to the return, or keeps it as customer credit (BR-C07: a `credit` refund plus the credit), inside RecordReturn's transaction with the order locked; VoidReturn asks
 * whether that refund is still live (BR-R06). Collections implements it (ReturnRefunds, bound in
 * CollectionsServiceProvider); without it nothing is ever collected, so NullRefundsPayments never refunds.
 */
interface RefundsPayments
{
    public function refund(Order $order, int $orderReturnId, string $amount, ?int $actorId): void;

    public function keepAsCredit(Order $order, int $orderReturnId, string $amount, ?int $actorId): void;

    public function hasLiveRefund(int $orderReturnId): bool;
}
