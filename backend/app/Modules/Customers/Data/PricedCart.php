<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

/**
 * The cart as the customer sees it (08-api-contract.md §3): live prices, lines dropped because their product
 * became inactive (BR-P04) or stopped taking pre-orders, the items total and the MOV check (BR-MOV02). Checkout re-checks
 * all of it (BR-PR06). The pre-order lines (BR-O14) are priced apart with their own indicative total; the minimum order
 * value does not apply to them (D88).
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedCartLine>  $lines
     * @param  list<array{product_name: string, reason: string}>  $removed
     * @param  list<PricedCartLine>  $preorderLines
     */
    public function __construct(
        public array $lines,
        public array $removed,
        public string $itemsTotal,
        public bool $movEnabled,
        public ?string $movAmount,
        public bool $movSatisfied,
        public array $preorderLines = [],
        public string $preorderTotal = '0.00',
    ) {}
}
