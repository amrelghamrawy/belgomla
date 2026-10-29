<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BR-PR11: the SKU units a bulk pricing touches, i.e. every active unit of the SKU of every product matching the shared
 * product filter (the selection BulkSetProductStatus uses), or only those sold in `$unitId` (V2 Task 13, D91), with their
 * tiers, unit and SKU's product loaded, in product order. Bundles are left out: a bundle's price is its bundle pricing
 * (D93, BR-P15), not its unit's base price.
 */
final readonly class BulkPricingTargetsQuery
{
    public function __construct(private FilteredProductsQuery $products) {}

    /**
     * @return Collection<int, SkuUnit>
     */
    public function get(ProductFilterData $filter, ?int $unitId = null): Collection
    {
        $units = new Collection;
        $products = $this->products->builder($filter)
            ->where('products.type', '!=', ProductType::Bundle)
            ->with([
                'skus.activeUnits' => fn (HasMany $query): HasMany => $unitId === null ? $query : $query->where('unit_id', $unitId),
                'skus.activeUnits.tiers',
                'skus.activeUnits.unit',
            ])
            ->orderBy('products.id')
            ->get();

        foreach ($products as $product) {
            foreach ($product->skus as $sku) {
                $sku->setRelation('product', $product);

                foreach ($sku->activeUnits as $unit) {
                    $units->push($unit->setRelation('sku', $sku));
                }
            }
        }

        return $units;
    }
}
