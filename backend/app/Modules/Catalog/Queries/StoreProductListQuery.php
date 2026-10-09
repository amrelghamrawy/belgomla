<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Storefront list (08-api-contract.md §1): active and coming_soon products only (BR-P04), the shared
 * filter, sort `name | -created_at | price | -price` (default newest first), paginated.
 */
final readonly class StoreProductListQuery
{
    public const array RELATIONS = ['category', 'sku.sellingUnit', 'mainImage'];

    public function __construct(private FilteredProductsQuery $filtered) {}

    /**
     * @param  array{0: string, 1: 'asc'|'desc'}|null  $sort
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(ProductFilterData $filter, ?array $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->filtered->builder($filter)->tap(new VisibleOnStore)->with(self::RELATIONS);

        DashboardProductListQuery::applySort($query, $sort ?? ['created_at', 'desc']);

        return $query->paginate($perPage);
    }
}
