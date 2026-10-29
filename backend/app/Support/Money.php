<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RoundingMode;

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

    /** bccomp of two amounts at 2 decimals: -1, 0 or 1. */
    public static function compare(string $a, string $b): int
    {
        return bccomp(self::numeric($a), self::numeric($b), 2);
    }

    /** The sum of two amounts, e.g. a running items total (BR-MOV02). */
    public static function add(string $a, string $b): string
    {
        return bcadd(self::numeric($a), self::numeric($b), 2);
    }

    /** The difference of two amounts, e.g. the balance left on an order (BR-C03). */
    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::numeric($a), self::numeric($b), 2);
    }

    /** A unit price times a whole quantity, e.g. a line total (BR-PR07). */
    public static function times(string $amount, int $quantity): string
    {
        return bcmul(self::numeric($amount), (string) $quantity, 2);
    }

    /** An amount shared over a whole count, half-up to the piaster (D27), e.g. an average order value (V2 Task 16). */
    public static function divide(string $amount, int $count): string
    {
        if ($count < 1) {
            throw new InvalidArgumentException('An amount is divided by a count of at least 1.');
        }

        return bcround(bcdiv(self::numeric($amount), (string) $count, 6), 2, RoundingMode::HalfAwayFromZero);
    }

    /** `part` as a percentage of `whole`, half-up to 2 decimals ("12.50"); null when the whole is not above zero (V2 Task 16). */
    public static function percentOf(string $part, string $whole): ?string
    {
        if (bccomp(self::numeric($whole), '0', 6) <= 0) {
            return null;
        }

        return bcround(bcdiv(bcmul(self::numeric($part), '100', 6), $whole, 6), 2, RoundingMode::HalfAwayFromZero);
    }

    /**
     * @return numeric-string
     */
    private static function numeric(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("\"{$amount}\" is not a money amount.");
        }

        return $amount;
    }
}
