<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BR-PR10 "recompute from percentages": a cover price change never reprices silently, so after one the
 * merchant asks for it explicitly. The base price and every tier that was entered as a % are derived
 * again from the current cover price; absolute prices stay. Written and audited by UpdateSkuPricing
 * (`sku.price_changed`, `sku.tiers_changed`), so a unit without stored percentages does not change. Every unit of the
 * product is recomputed off its own cover price (V2 Task 13, D91), in one transaction (owned here).
 */
final readonly class RecomputeFromPercentages
{
    public function __construct(
        private PriceResolver $resolver,
        private UpdateSkuPricing $update,
    ) {}

    /**
     * @param  Collection<int, SkuUnit>  $units
     * @return Collection<int, SkuUnit>
     */
    public function handle(Collection $units): Collection
    {
        return DB::transaction(fn (): Collection => $units->map(fn (SkuUnit $unit): SkuUnit => $this->recompute($unit)));
    }

    private function recompute(SkuUnit $unit): SkuUnit
    {
        $unit->loadMissing('tiers');

        return $this->update->handle($unit, new SkuPricingData(
            basePrice: $unit->base_price_percent === null ? $unit->base_price : null,
            basePricePercent: $unit->base_price_percent,
            moq: $unit->moq,
            tiers: array_map(
                fn (TierData $tier): TierData => $tier->discountPercent === null ? $tier : new TierData($tier->minQuantity, discountPercent: $tier->discountPercent),
                $this->resolver->tiers($unit),
            ),
            coverPriceGiven: false,
        ));
    }
}
