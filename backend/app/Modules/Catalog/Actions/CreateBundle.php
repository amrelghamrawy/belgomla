<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Services\BundleReferences;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Catalog\Services\ProductSnapshot;
use App\Modules\Pricing\Actions\SaveBundlePricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Services\BundlePricer;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/bundles (BR-P15, V2 Task 15, D93): creates a bundle product in one transaction (owned here): the
 * product (`type = bundle`), its one SKU sold in one unit (factor 1, the bundle's MOQ, no tiers, no cover price), its
 * components (SaveBundleItems) and its pricing (Pricing's SaveBundlePricing); the unit's base price is what BundlePricer
 * gives (a fixed price, or the components' sum less the %), written through SaveSkuUnits like any unit's. A bundle has no
 * stock row. Components must be units of active simple products (BundleReferences). Audited `product.created` with the
 * bundle's components and pricing.
 */
final readonly class CreateBundle
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

    public function handle(BundleData $data, ?int $createdBy = null): Product
    {
        [$category, $units] = $this->references->check($data);
        $customFields = $this->fields->validate($data->customFields, $category->kind, required: false);

        return DB::transaction(function () use ($data, $units, $customFields, $createdBy): Product {
            $product = Product::query()->create([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'status' => $data->status ?? ProductStatus::Active,
                'type' => ProductType::Bundle,
                'custom_fields' => $customFields,
                'created_by' => $createdBy,
            ]);

            $sku = Sku::query()->create(['product_id' => $product->id, 'code' => $data->code, 'base_unit_id' => $data->unitId, 'sort_order' => 0]);
            $items = $this->items->handle($sku, $data->components, $units);
            $price = $this->pricer->price($data->pricing, $items);

            $this->units->handle($sku, [new SkuUnitData(null, $data->unitId, 1, true, new SkuPricingData($price, moq: $data->moq, tiers: []))], path: 'pricing');
            $this->pricing->handle($sku, $data->pricing);

            $product->load(ProductSnapshot::RELATIONS);
            $this->audit->log('product.created', $product, null, $this->snapshot->ofBundle($product));

            return $product;
        });
    }
}
