<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Modules\Pricing\Enums\PriceSource;

/**
 * BR-PR14 (V2 Task 11): what the storefront lists for one SKU and customer: the price of the smallest order (`basePrice`,
 * the base price, the group price or the negotiated one, with its `source`), the % it is off the cover price, and the
 * quantity tiers that still lower it. A negotiated price has no tiers (it applies at every quantity); a group price keeps
 * only the tiers below it, and so does a promotion price (BR-PR15, V2 Task 12, with its `promotion`). Without a context it is the SKU as stored (V1).
 */
final readonly class ListedPrice
{
    /**
     * @param  list<TierData>  $tiers
     */
    public function __construct(
        public string $basePrice,
        public PriceSource $source,
        public ?string $discountPercent,
        public array $tiers,
        public ?PromotionPrice $promotion = null,
    ) {}
}
