<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Sku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * D36 low stock: Available ≤ MOQ × conversion factor of the SKU's smallest active unit (V2 Task 13, D91), i.e. at most
 * one minimum order of it left, compared in base units (BR-U03). A SKU without a stock row has nothing available, so it is low too.
 * `constrain()` is shared by the inventory list and Catalog's dashboard product list; `builder()` is the
 * low-stock report (D5): SKUs of products that are not inactive.
 */
final readonly class LowStockQuery
{
    /** D36: MOQ × factor of the SKU's smallest active unit (the fewest base units), 0 when it has none. */
    private const string THRESHOLD = "COALESCE((SELECT u.moq * u.conversion_factor FROM sku_units u WHERE u.sku_id = skus.id AND u.status = 'active' ORDER BY u.conversion_factor, u.id LIMIT 1), 0)";

    /**
     * @param  Builder<Sku>  $skus
     * @return Builder<Sku>
     */
    public function constrain(Builder $skus): Builder
    {
        return $skus->whereNotExists(fn (QueryBuilder $stock): QueryBuilder => $stock
            ->from('sku_inventory')
            ->whereColumn('sku_inventory.sku_id', 'skus.id')
            ->whereRaw('sku_inventory.on_hand - sku_inventory.reserved > '.self::THRESHOLD));
    }

    /**
     * @return Builder<Sku>
     */
    public function builder(): Builder
    {
        return $this->constrain(
            Sku::query()->where('skus.status', 'active')->whereHas('product', fn (Builder $products): Builder => $products->where('status', '!=', ProductStatus::Inactive)), // an inactive variant is not restocked (BR-P14)
        );
    }
}
