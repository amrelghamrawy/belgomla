<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is a 2-decimal string ("150.00") end to end (08-api-contract.md §0). bcmath, never floats.
 */
final class Money
{
    /**
     * Normalises a validated amount ("90", "90.5", "90.50") to exactly two decimals.
     * Amounts with more than two decimals are rejected rather than silently rounded.
     */
    public static function normalize(string $amount): string
    {
        $amount = mb_trim($amount);

        if (! is_numeric($amount) || preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            throw new InvalidArgumentException("\"{$amount}\" is not a money amount with at most 2 decimals.");
        }

        return bcadd($amount, '0', 2);
    }

    public static function normalizeOrNull(?string $amount): ?string
    {
        return $amount === null ? null : self::normalize($amount);
    }
}
