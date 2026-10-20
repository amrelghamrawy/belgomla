<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Exceptions\DiscountTooLarge;
use InvalidArgumentException;
use RoundingMode;

/**
 * BR-PR08 (V2 Task 08, D85): the money math of a manual discount, on strings with bcmath, never floats. A percent is
 * rounded **half-up to the piaster** like every % price (D27): 10.10 at 25% off = 7.575 → 7.58. A discount never
 * takes a unit price below 0.01, nor an order total below 0.01 (`422 discount_too_large`), so the order discount
 * always stays under the items total.
 */
final readonly class DiscountCalculator
{
    /** The unit price after a line discount, e.g. ("90.00", percent, "10") → "81.00", ("90.00", amount, "5") → "85.00". */
    public function unitAfter(string $listPrice, DiscountType $type, string $value): string
    {
        $list = $this->numeric($listPrice);
        $value = $this->positive($value);

        $unit = match ($type) {
            DiscountType::Amount => bcsub($list, $value, 2),
            DiscountType::Percent => bcround(bcdiv(bcmul($list, bcsub('100', $value, 2), 4), '100', 6), 2, RoundingMode::HalfAwayFromZero),
        };

        if (bccomp($unit, '0.01', 2) < 0) {
            throw DiscountTooLarge::unit($listPrice);
        }

        return $unit;
    }

    /** What an order discount takes off the items total, e.g. ("1390.00", percent, "10") → "139.00". */
    public function orderAmount(string $itemsTotal, DiscountType $type, string $value): string
    {
        $total = $this->numeric($itemsTotal);
        $value = $this->positive($value);

        $amount = match ($type) {
            DiscountType::Amount => bcadd($value, '0', 2),
            DiscountType::Percent => bcround(bcdiv(bcmul($total, $value, 4), '100', 6), 2, RoundingMode::HalfAwayFromZero),
        };

        if (bccomp(bcsub($total, $amount, 2), '0.01', 2) < 0) {
            throw DiscountTooLarge::order($itemsTotal);
        }

        return $amount;
    }

    /**
     * @return numeric-string
     */
    private function numeric(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("\"{$amount}\" is not a money amount.");
        }

        return $amount;
    }

    /**
     * @return numeric-string
     */
    private function positive(string $value): string
    {
        if (! is_numeric($value) || bccomp($value, '0', 2) <= 0) {
            throw new InvalidArgumentException("A discount must be above 0, \"{$value}\" given.");
        }

        return $value;
    }
}
