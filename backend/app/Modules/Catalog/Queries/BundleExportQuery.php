<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\LazyCollection;

/**
 * The bundles of the catalog export (V2 Task 15, D93): the bundle products the dashboard filter selects (every status),
 * oldest first, read lazily in chunks with their SKU's selling unit and components (each component's unit and SKU)
 * eager-loaded, for the `bundles_v1` sheet (BundleSheet).
 */
final readonly class BundleExportQuery
{
    public function __construct(private FilteredProductsQuery $filtered) {}

    /**
     * @return LazyCollection<int, Product>
     */
    public function get(ProductFilterData $filter): LazyCollection
    {
        return $this->filtered->builder($filter)
            ->where('products.type', ProductType::Bundle)
            ->with(['skus.defaultUnit.unit', 'skus.bundleItems.componentUnit.unit', 'skus.bundleItems.componentUnit.sku'])
            ->lazyById(CatalogExportQuery::CHUNK, 'products.id', 'id');
    }
}
