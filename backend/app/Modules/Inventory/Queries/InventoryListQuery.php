<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/inventory: every SKU of the tenant (any product status) with its stock, searched
 * with the catalog's `q` (name, SKU code, option labels; D32), optionally only low stock (D36).
 * Sort `code` (default), `available`, `-available`.
 */
final readonly class InventoryListQuery
{
    public const array SORTS = ['code', '-code', 'available', '-available'];

    public function __construct(
        private FilteredProductsQuery $products,
        private LowStockQuery $lowStock,
    ) {}

    /**
     * @param  array{0: string, 1: 'asc'|'desc'}|null  $sort
     * @return LengthAwarePaginator<int, Sku>
     */
    public function paginate(?string $q, bool $lowStock, ?array $sort, int $perPage): LengthAwarePaginator
    {
        $query = Sku::query()
            ->select('skus.*')
            ->leftJoin('sku_inventory', 'sku_inventory.sku_id', '=', 'skus.id')
            ->with(['product', 'inventory']);

        if ($q !== null) {
            $query->whereIn('skus.product_id', $this->products->builder(new ProductFilterData(q: $q))->select('products.id'));
        }

        if ($lowStock) {
            $this->lowStock->constrain($query);
        }

        [$column, $direction] = $sort ?? ['code', 'asc'];

        if ($column === 'available') {
            $query->orderByRaw('coalesce(sku_inventory.on_hand - sku_inventory.reserved, 0) '.$direction);
        } else {
            $query->orderBy('skus.code', $direction);
        }

        return $query->orderBy('skus.id')->paginate($perPage);
    }
}
