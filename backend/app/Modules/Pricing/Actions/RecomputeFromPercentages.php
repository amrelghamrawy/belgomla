<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;

/**
 * BR-PR10 "recompute from percentages": a cover price change never reprices silently, so after one the
 * merchant asks for it explicitly. The base price and every tier that was entered as a % are derived
 * again from the current cover price; absolute prices stay. Written and audited by UpdateSkuPricing
 * (`sku.price_changed`, `sku.tiers_changed`), so a SKU without stored percentages does not change.
 */
final readonly class RecomputeFromPercentages
{
    public function __construct(
        private PriceResolver $resolver,
        private UpdateSkuPricing $update,
    ) {}

    public function handle(Sku $sku): Sku
    {
        $sku->loadMissing('tiers');

        return $this->update->handle($sku, new SkuPricingData(
            basePrice: $sku->base_price_percent === null ? $sku->base_price : null,
            basePricePercent: $sku->base_price_percent,
            moq: $sku->moq,
            tiers: array_map(
                fn (TierData $tier): TierData => $tier->discountPercent === null ? $tier : new TierData($tier->minQuantity, discountPercent: $tier->discountPercent),
                $this->resolver->tiers($sku),
            ),
            coverPriceGiven: false,
        ));
    }
}
