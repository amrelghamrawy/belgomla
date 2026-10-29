<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Exceptions\BundleRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Services\BundleReferences;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Catalog\Services\ProductSnapshot;
use App\Modules\Pricing\Actions\SaveBundlePricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Services\BundlePricer;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/dashboard/bundles/{product} (BR-P15, D93): replaces a bundle's fields, code, unit, MOQ, components and pricing
 * (not its status, which has its own endpoint) in one transaction (owned here). The unit's base price is priced again
 * (BundlePricer) and written through SaveSkuUnits, so a change is audited `sku.price_changed` on the unit; a new unit
 * archives the old one (carts on it drop the line, D91). Orders already placed keep their snapshot lines (BR-O05).
 * A simple product is not a bundle here (404). Audited `product.updated` with what changed.
 */
final readonly class UpdateBundle
{
    public function __construct(
        private BundleReferences $references,
        private CustomFieldValidator $fields,
        private SaveBundleItems $items,
        private SaveSkuUnits $units,
        private SaveBundlePricing $pricing,
        private BundlePricer $pricer,
        private ProductSnapshot $snapshot,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $product, BundleData $data): Product
    {
        if (! $product->isBundle()) {
            throw BundleRefused::notABundle();
        }

        $product->load(ProductSnapshot::RELATIONS);
        [$category, $units] = $this->references->check($data, $product);
        $customFields = $this->fields->validate($data->customFields, $category->kind, $product->custom_fields, required: false);
        $old = $this->snapshot->ofBundle($product);

        return DB::transaction(function () use ($product, $data, $units, $customFields, $old): Product {
            $product->update([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'custom_fields' => $customFields,
            ]);

            $sku = $product->skus()->firstOrFail();
            $sku->update(['code' => $data->code, 'base_unit_id' => $data->unitId]);
            $items = $this->items->handle($sku, $data->components, $units);
            $price = $this->pricer->price($data->pricing, $items);
            $current = SkuUnit::query()->where('sku_id', $sku->id)->where('unit_id', $data->unitId)->value('id');

            $this->units->handle($sku, [new SkuUnitData(is_numeric($current) ? (int) $current : null, $data->unitId, 1, true, new SkuPricingData($price, moq: $data->moq, tiers: []))], path: 'pricing');
            $this->pricing->handle($sku, $data->pricing);

            $product->load(ProductSnapshot::RELATIONS);
            $this->audit->log('product.updated', $product, $old, $this->snapshot->ofBundle($product));

            return $product;
        });
    }
}
