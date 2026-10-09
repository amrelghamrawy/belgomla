<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Sku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * D36 low stock: Available ≤ MOQ × conversion factor, i.e. at most one minimum order left, compared in
 * base units (BR-U03). A SKU without a stock row has nothing available, so it is low too.
 * `constrain()` is shared by the inventory list and Catalog's dashboard product list; `builder()` is the
 * low-stock report (D5): SKUs of products that are not inactive.
 */
final readonly class LowStockQuery
{
    /**
     * @param  Builder<Sku>  $skus
     * @return Builder<Sku>
     */
    public function constrain(Builder $skus): Builder
    {
        return $skus->whereNotExists(fn (QueryBuilder $stock): QueryBuilder => $stock
            ->from('sku_inventory')
            ->whereColumn('sku_inventory.sku_id', 'skus.id')
            ->whereRaw('sku_inventory.on_hand - sku_inventory.reserved > skus.moq * skus.conversion_factor'));
    }

    /**
     * @return Builder<Sku>
     */
    public function builder(): Builder
    {
        return $this->constrain(
            Sku::query()->whereHas('product', fn (Builder $products): Builder => $products->where('status', '!=', ProductStatus::Inactive)),
        );
    }
}
