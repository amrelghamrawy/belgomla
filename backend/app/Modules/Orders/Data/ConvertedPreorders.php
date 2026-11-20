<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/**
 * What ConvertPreorders left (BR-O14, D88), the merchant's summary: the normal orders it created (each with the
 * pre-order it came from), how many pre-orders got only part of what they asked for, and how many got nothing (no stock
 * left for them, or the customer can no longer order).
 */
final readonly class ConvertedPreorders
{
    /**
     * @param  list<array{number: int, preorder_number: int, quantity: int, order_total: string}>  $orders
     */
    public function __construct(
        public array $orders,
        public int $partially,
        public int $untouched,
    ) {}
}
