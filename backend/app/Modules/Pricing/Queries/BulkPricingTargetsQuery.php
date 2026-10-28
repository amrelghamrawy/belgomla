<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BR-PR11: the SKU units a bulk pricing touches, i.e. every active unit of the SKU of every product matching the shared
 * product filter (the selection BulkSetProductStatus uses), or only those sold in `$unitId` (V2 Task 13, D91), with their
 * tiers, unit and SKU's product loaded, in product order.
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
            ->with([
                'sku.activeUnits' => fn (HasMany $query): HasMany => $unitId === null ? $query : $query->where('unit_id', $unitId),
                'sku.activeUnits.tiers',
                'sku.activeUnits.unit',
            ])
            ->orderBy('products.id')
            ->get();

        foreach ($products as $product) {
            if (! $product->sku instanceof Sku) {
                continue;
            }

            $product->sku->setRelation('product', $product);

            foreach ($product->sku->activeUnits as $unit) {
                $units->push($unit->setRelation('sku', $product->sku));
            }
        }

        return $units;
    }
}
