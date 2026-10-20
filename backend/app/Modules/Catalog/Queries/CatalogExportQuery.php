<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\LazyCollection;

/**
 * The catalog export selection (V2 Task 06): the dashboard filter (every status), oldest product first, read lazily
 * in chunks of `CHUNK` with the SKU's units, tiers and stock row eager-loaded, so a catalog of any size streams in
 * flat memory. Custom field values are on the product; their labels come from CatalogRowMapper's lookups.
 */
final readonly class CatalogExportQuery
{
    public const int CHUNK = 500;

    public function __construct(private FilteredProductsQuery $filtered) {}

    /**
     * @return LazyCollection<int, Product>
     */
    public function get(ProductFilterData $filter): LazyCollection
    {
        return $this->filtered->builder($filter)
            ->with(['sku.sellingUnit', 'sku.baseUnit', 'sku.tiers', 'sku.inventory'])
            ->lazyById(self::CHUNK, 'products.id', 'id');
    }

    public function count(ProductFilterData $filter): int
    {
        return $this->filtered->builder($filter)->count();
    }
}
