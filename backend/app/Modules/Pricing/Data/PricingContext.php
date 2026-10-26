<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Support\Money;

/**
 * BR-PR12..PR15 (V2 Tasks 11, 12; D89, D90): what a price depends on beyond the SKU, read once per request by Customers'
 * PricingContextQuery so the PriceResolver stays free of the database: the % off the base price of the customer's active
 * group (null = no group, or an archived one), the prices negotiated with the customer, by SKU id, and the live
 * promotion of each SKU being priced (ActivePromotions, by SKU id). A guest has a context only when a promotion applies
 * to one of the SKUs priced; otherwise none (null).
 */
final readonly class PricingContext
{
    /**
     * @param  array<int, string>  $customerPrices  sku_id => unit price
     * @param  array<int, PromotionPrice>  $promotions  sku_id => the SKU's live promotion
     */
    public function __construct(
        public ?string $groupDiscountPercent = null,
        public array $customerPrices = [],
        public array $promotions = [],
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

    public function promotion(int $skuId): ?PromotionPrice
    {
        return $this->promotions[$skuId] ?? null;
    }
}
