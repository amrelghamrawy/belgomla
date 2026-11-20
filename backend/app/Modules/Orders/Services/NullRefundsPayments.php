<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\RefundsPayments;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Models\Order;

/** RefundsPayments without the Collections module: nothing was collected, so nothing is paid back. */
final readonly class NullRefundsPayments implements RefundsPayments
{
    public function refund(Order $order, int $orderReturnId, string $amount, ?int $actorId, RefundMethod $method = RefundMethod::Cash, ?string $reference = null): void {}

    public function keepAsCredit(Order $order, int $orderReturnId, string $amount, ?int $actorId): void {}

    public function hasLiveRefund(int $orderReturnId): bool
    {
        return false;
    }
}
