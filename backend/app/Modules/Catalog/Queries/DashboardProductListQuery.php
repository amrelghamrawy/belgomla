<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard product list: every status (inactive included), filtered, sorted, paginated.
 */
final readonly class DashboardProductListQuery
{
    public function __construct(private FilteredProductsQuery $filtered) {}

    /**
     * @param  array{0: string, 1: 'asc'|'desc'}|null  $sort  default newest first
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(ProductFilterData $filter, ?array $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->filtered->builder($filter)->with(['category', 'sku.sellingUnit', 'sku.baseUnit', 'mainImage']);

        self::applySort($query, $sort ?? ['created_at', 'desc']);

        return $query->paginate($perPage);
    }

    /**
     * Shared with the store list: name, created_at or the SKU base price, then id for stable pages.
     *
     * @param  Builder<Product>  $query
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     */
    public static function applySort(Builder $query, array $sort): void
    {
        [$column, $direction] = $sort;

        match ($column) {
            'price' => $query->orderBy(
                Sku::query()->select('base_price')->whereColumn('skus.product_id', 'products.id')->orderBy('skus.id')->limit(1),
                $direction,
            ),
            'name' => $query->orderBy('products.name', $direction),
            default => $query->orderBy('products.created_at', $direction),
        };

        $query->orderBy('products.id', $direction);
    }
}
