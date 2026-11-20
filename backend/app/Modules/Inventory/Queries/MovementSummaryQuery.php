<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * V2 Task 16: the tenant's stock movements between two instants summed per SKU and type (BR-INV06), in signed base
 * units (a sale is negative, a voided return too), for the inventory analytics report. Reservations are not movements.
 */
final readonly class MovementSummaryQuery
{
    /** One row per SKU that moved: `sku_id` and one column per movement type (`opening`, `adjustment`, `sale`, `return`). */
    public function bySku(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $query = InventoryMovement::query()->toBase()
            ->whereBetween('inventory_movements.created_at', [$start, $end])
            ->select('inventory_movements.sku_id')
            ->groupBy('inventory_movements.sku_id');

        foreach (MovementType::cases() as $type) {
            $query->selectRaw("coalesce(sum(inventory_movements.quantity) filter (where inventory_movements.type = '{$type->value}'), 0) as \"{$type->value}\"");
        }

        return $query;
    }
}
