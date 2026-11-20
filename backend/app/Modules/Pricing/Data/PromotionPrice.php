<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use Carbon\CarbonImmutable;

/**
 * BR-PR15 (V2 Task 12, D90): the live promotion of one SKU, as ActivePromotions reads it for the PricingContext: which
 * promotion, its name, its % off the base price and when it ends (the storefront's «ينتهي خلال …»).
 */
final readonly class PromotionPrice
{
    public function __construct(
        public int $promotionId,
        public string $name,
        public string $discountPercent,
        public CarbonImmutable $endsAt,
    ) {}

    /**
     * The `promotion` object of the storefront and cart (08-api-contract.md §1): the end as ISO-8601 UTC.
     *
     * @return array{id: int, name: string, discount_percent: string, ends_at: string}
     */
    public function summary(): array
    {
        return ['id' => $this->promotionId, 'name' => $this->name, 'discount_percent' => $this->discountPercent, 'ends_at' => $this->endsAt->toIso8601String()];
    }
}
