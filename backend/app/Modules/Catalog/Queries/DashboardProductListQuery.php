<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Inventory\Queries\LowStockQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard product list: every status (inactive included), filtered, sorted, paginated. `lowStock` keeps
 * products whose SKU is low on stock, as Inventory defines it (D36).
 */
final readonly class DashboardProductListQuery
{
    public function __construct(
        private FilteredProductsQuery $filtered,
        private LowStockQuery $lowStock,
    ) {}

    /**
     * @param  array{0: string, 1: 'asc'|'desc'}|null  $sort  default newest first
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(ProductFilterData $filter, ?array $sort, int $perPage, bool $lowStock = false): LengthAwarePaginator
    {
        $query = $this->filtered->builder($filter)->with(['category', 'sku.activeUnits.unit', 'sku.baseUnit', 'mainImage']);

        if ($lowStock) {
            $query->whereHas('skus', fn (Builder $skus): Builder => $this->lowStock->constrain($skus));
        }

        self::applySort($query, $sort ?? ['created_at', 'desc']);

        return $query->paginate($perPage);
    }

    /**
     * Shared with the store list: name, created_at or the base price of the SKU's default unit (D91), then id for stable pages.
     *
     * @param  Builder<Product>  $query
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     */
    public static function applySort(Builder $query, array $sort): void
    {
        [$column, $direction] = $sort;

        match ($column) {
            'price' => $query->orderBy(
                SkuUnit::query()->select('sku_units.base_price')->join('skus', 'skus.id', '=', 'sku_units.sku_id')->whereColumn('skus.product_id', 'products.id')->where('sku_units.is_default', true)->orderBy('skus.id')->limit(1),
                $direction,
            ),
            'name' => $query->orderBy('products.name', $direction),
            default => $query->orderBy('products.created_at', $direction),
        };

        $query->orderBy('products.id', $direction);
    }
}
