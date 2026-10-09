<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

/**
 * The cart as the customer sees it (08-api-contract.md §3): live prices, lines dropped because their product
 * became inactive (BR-P04), the items total and the MOV check (BR-MOV02). Checkout re-checks all of it (BR-PR06).
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedCartLine>  $lines
     * @param  list<array{product_name: string, reason: string}>  $removed
     */
    public function __construct(
        public array $lines,
        public array $removed,
        public string $itemsTotal,
        public bool $movEnabled,
        public ?string $movAmount,
        public bool $movSatisfied,
    ) {}
}
