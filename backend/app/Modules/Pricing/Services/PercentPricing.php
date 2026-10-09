<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use InvalidArgumentException;
use RoundingMode;

/**
 * BR-PR10 money math between a cover price and a % off it, on strings with bcmath, never floats.
 *
 * Rounding rule (D27): the exact result is rounded **half-up to the piaster** (2 decimals), e.g.
 * 10.10 at 25% off = 7.575 → 7.58. Prices are positive, so half-up equals half away from zero.
 */
final readonly class PercentPricing
{
    /**
     * cover × (100 − percent) / 100, e.g. ("120.00", "25.00") → "90.00".
     */
    public function priceFromPercent(string $coverPrice, string $percent): string
    {
        $cover = $this->positive($coverPrice, 'cover price');
        $percent = $this->percent($percent);

        $exact = bcdiv(bcmul($cover, bcsub('100', $percent, 2), 4), '100', 6);

        return bcround($exact, 2, RoundingMode::HalfAwayFromZero);
    }

    /**
     * The discount a price implies off the cover price, e.g. ("120.00", "90.00") → "25.00".
     * Null when the price is above the cover price: there is no discount to show.
     */
    public function percentOff(string $coverPrice, string $price): ?string
    {
        $cover = $this->positive($coverPrice, 'cover price');
        $price = $this->positive($price, 'price');

        if (bccomp($price, $cover, 2) > 0) {
            return null;
        }

        $exact = bcdiv(bcmul(bcsub($cover, $price, 2), '100', 2), $cover, 10);

        return bcround($exact, 2, RoundingMode::HalfAwayFromZero);
    }

    /**
     * @return numeric-string
     */
    private function positive(string $amount, string $name): string
    {
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException("The {$name} must be a positive amount, \"{$amount}\" given.");
        }

        return $amount;
    }

    /**
     * @return numeric-string
     */
    private function percent(string $percent): string
    {
        if (! is_numeric($percent) || bccomp($percent, '0', 2) <= 0 || bccomp($percent, '100', 2) >= 0) {
            throw new InvalidArgumentException("A discount percent must be above 0 and below 100, \"{$percent}\" given.");
        }

        return $percent;
    }
}
