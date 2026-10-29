<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\BundlePricing;
use App\Modules\Pricing\Services\BundlePricer;
use App\Support\Money;

/**
 * BR-P15 (D93): a bundle priced as the components' sum less a % follows its components' base prices. Called by
 * UpdateSkuPricing whenever a unit's base price changes (the product form, the per-unit pricing endpoint, bulk pricing,
 * recompute, the catalog import): every `percent_off_sum` bundle holding one of `$componentUnitIds` gets its unit's base
 * price recomputed, audited as `sku.price_changed` on that unit (with the caller's batch id). A fixed-price bundle does
 * not move. Joins the caller's transaction.
 */
final readonly class RepriceBundles
{
    public function __construct(
        private BundlePricer $pricer,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $componentUnitIds
     */
    public function handle(array $componentUnitIds, ?string $batchId = null): void
    {
        $bundleSkuIds = BundleItem::query()->whereIn('component_sku_unit_id', $componentUnitIds)->distinct()->pluck('bundle_sku_id')->all();

        if ($bundleSkuIds === []) {
            return;
        }

        $pricings = BundlePricing::query()->whereIn('bundle_sku_id', $bundleSkuIds)->where('mode', BundlePriceMode::PercentOffSum)->get();

        foreach ($pricings as $pricing) {
            $this->reprice($pricing, $batchId);
        }
    }

    private function reprice(BundlePricing $pricing, ?string $batchId): void
    {
        $items = BundleItem::query()->with('componentUnit')->where('bundle_sku_id', $pricing->bundle_sku_id)->get();
        $price = $this->pricer->price(new BundlePricingData($pricing->mode, $pricing->value), $items);

        foreach (SkuUnit::query()->where('sku_id', $pricing->bundle_sku_id)->get() as $unit) {
            if (Money::compare($unit->base_price, $price) === 0) {
                continue;
            }

            $old = ['cover_price' => $unit->cover_price, 'base_price' => $unit->base_price, 'base_price_percent' => $unit->base_price_percent];
            $unit->forceFill(['base_price' => $price])->save();
            $this->audit->log('sku.price_changed', $unit, $old, [...$old, 'base_price' => $unit->base_price], $batchId);
        }
    }
}
