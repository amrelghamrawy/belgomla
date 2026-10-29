<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RoundingMode;

/**
 * Splits an amount over parts in proportion to their weights (BR-P15, D93: a bundle's price over its components, by
 * their own prices), in piasters: every part but the last is rounded half-up (D27), the last takes the remainder, so the
 * shares add up to exactly the amount. Should the rounding of the first parts overshoot (many tiny parts), the largest of
 * them give a piaster back until the last is not negative. Without any weight the amount is split evenly the same way.
 */
final class Allocation
{
    /**
     * @param  list<string>  $weights  money amounts, ≥ 0
     * @return list<string> one share per weight, in order, adding up to `$amount`
     */
    public static function proportional(string $amount, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Nothing to allocate over.');
        }

        $total = self::piasters($amount);
        $weightPiasters = array_map(self::piasters(...), $weights);
        $sum = array_sum($weightPiasters);

        if ($sum === 0) {
            $weightPiasters = array_fill(0, count($weights), 1);
            $sum = count($weights);
        }

        $shares = [];
        $last = count($weightPiasters) - 1;

        foreach ($weightPiasters as $index => $weight) {
            if ($index < $last) {
                $shares[] = (int) bcround(bcdiv(bcmul((string) $total, (string) $weight), (string) $sum, 6), 0, RoundingMode::HalfAwayFromZero);
            }
        }

        while ($shares !== [] && array_sum($shares) > $total) {
            $largest = (int) array_search(max($shares), $shares, true);
            $shares[$largest]--;
        }

        $shares[] = $total - array_sum($shares);

        $amounts = [];

        foreach ($shares as $share) {
            $amounts[] = bcdiv((string) $share, '100', 2);
        }

        return $amounts;
    }

    private static function piasters(string $amount): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("\"{$amount}\" is not a money amount.");
        }

        return (int) bcmul($amount, '100', 0);
    }
}
