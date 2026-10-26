<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Support\Money;

/**
 * BR-PR12..PR14 (V2 Task 11, D89): what a customer's prices depend on beyond the SKU, read once per request by
 * Customers' PricingContextQuery so the PriceResolver stays free of the database: the % off the base price of the
 * customer's active group (null = no group, or an archived one) and the prices negotiated with the customer, by SKU id.
 * A guest has no context at all (null).
 */
final readonly class PricingContext
{
    /**
     * @param  array<int, string>  $customerPrices  sku_id => unit price
     */
    public function __construct(
        public ?string $groupDiscountPercent = null,
        public array $customerPrices = [],
    ) {}

    public function customerPrice(int $skuId): ?string
    {
        return $this->customerPrices[$skuId] ?? null;
    }

    /** A group discount that lowers prices: a 0% level changes nothing. */
    public function groupDiscount(): ?string
    {
        return $this->groupDiscountPercent !== null && Money::compare($this->groupDiscountPercent, '0.00') > 0 ? $this->groupDiscountPercent : null;
    }
}
