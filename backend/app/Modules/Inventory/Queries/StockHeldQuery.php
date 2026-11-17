<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Inventory\Models\SkuInventory;
use Illuminate\Database\Eloquent\Builder;

/** D99 c: whether a SKU has anything on hand or reserved, for Catalog's base unit lock. */
final readonly class StockHeldQuery
{
    public function holds(int $skuId): bool
    {
        return SkuInventory::query()
            ->whereKey($skuId)
            ->where(fn (Builder $query): Builder => $query->where('on_hand', '>', 0)->orWhere('reserved', '>', 0))
            ->exists();
    }
}
