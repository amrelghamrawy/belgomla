<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Support\Money;
use RoundingMode;

/**
 * BR-R04, D85: what goods brought back are worth. A line returns at its own price after its line discount, less the
 * order discount's share (discount_amount / items_total), i.e. × order_total / items_total, rounded half-up to the
 * piaster (D27). Without an order discount an amount is unchanged (D74). RecordReturn lets the last return of an
 * order absorb the rounding.
 */
final readonly class ReturnPricing
{
    public function prorated(Order $order, string $amount): string
    {
        if (Money::compare($order->discount_amount, '0.00') === 0) {
            return $amount;
        }

        return bcround(bcdiv(bcmul($this->numeric($amount), $this->numeric($order->order_total), 4), $this->numeric($order->items_total), 6), 2, RoundingMode::HalfAwayFromZero);
    }

    /**
     * @return numeric-string
     */
    private function numeric(string $amount): string
    {
        return is_numeric($amount) ? $amount : '0';
    }
}
