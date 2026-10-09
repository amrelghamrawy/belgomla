<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** GET /api/dashboard/inventory/{sku}/movements: the SKU's ledger, newest first (BR-INV06). */
final readonly class MovementsQuery
{
    /**
     * @return LengthAwarePaginator<int, InventoryMovement>
     */
    public function paginate(Sku $sku, int $perPage): LengthAwarePaginator
    {
        return InventoryMovement::query()->where('sku_id', $sku->id)->orderByDesc('id')->paginate($perPage);
    }
}
