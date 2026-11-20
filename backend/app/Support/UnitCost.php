<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RoundingMode;

/**
 * A cost per base unit (D96): a 4-decimal string ("5.5000"), bcmath, never floats. Money the merchant types is per a
 * selling unit with 2 decimals (App\Support\Money); a carton price ÷ its factor rarely divides to the piaster, so the
 * cost of one base unit keeps 4 decimals, half-up.
 */
final class UnitCost
{
    /** A price paid per one unit of `factor` base units ("300.00" per carton of 50) as the cost of one base unit ("6.0000"). */
    public static function perBaseUnit(string $price, int $factor): string
    {
        if ($factor < 1) {
            throw new InvalidArgumentException('A conversion factor is at least 1.');
        }

        return self::round(bcdiv(Money::normalize($price), (string) $factor, 8));
    }

    /**
     * BR-INV09: the weighted average after `received` base units come in at `cost`:
     * (on hand × average + received × cost) ÷ (on hand + received). With no average yet, or nothing on hand, the
     * received cost is taken as it is.
     */
    public static function average(int $onHand, ?string $average, int $received, string $cost): string
    {
        if ($received < 1) {
            throw new InvalidArgumentException('Goods received are at least one base unit.');
        }

        if ($average === null || $onHand <= 0) {
            return self::round(self::numeric($cost));
        }

        $value = bcadd(bcmul((string) $onHand, self::numeric($average), 8), bcmul((string) $received, self::numeric($cost), 8), 8);

        return self::round(bcdiv($value, (string) ($onHand + $received), 8));
    }

    /** A cost as stored, to 4 decimals ("5.5" → "5.5000"); null stays null. */
    public static function normalizeOrNull(?string $cost): ?string
    {
        return $cost === null ? null : self::round(self::numeric($cost));
    }

    /**
     * @param  numeric-string  $value
     */
    private static function round(string $value): string
    {
        return bcround($value, 4, RoundingMode::HalfAwayFromZero);
    }

    /**
     * @return numeric-string
     */
    private static function numeric(string $value): string
    {
        if (! is_numeric($value) || bccomp($value, '0', 8) < 0) {
            throw new InvalidArgumentException("\"{$value}\" is not a cost.");
        }

        return $value;
    }
}
