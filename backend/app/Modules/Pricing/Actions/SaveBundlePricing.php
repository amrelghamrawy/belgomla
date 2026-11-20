<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Models\BundlePricing;

/**
 * BR-P15 (D93): stores how a bundle is priced (`bundle_pricing`). The price it gives is written to the bundle unit by
 * Catalog's CreateBundle / UpdateBundle (through SaveSkuUnits and UpdateSkuPricing, from BundlePricer::price) and kept up
 * to date by RepriceBundles when a component's price changes. Joins the caller's transaction.
 */
final readonly class SaveBundlePricing
{
    public function handle(Sku $bundle, BundlePricingData $data): void
    {
        BundlePricing::query()->updateOrCreate(['bundle_sku_id' => $bundle->id], ['mode' => $data->mode, 'value' => $data->value]);
    }
}
