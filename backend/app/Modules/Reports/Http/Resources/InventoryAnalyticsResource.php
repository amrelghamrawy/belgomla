<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One SKU of the inventory analytics report (V2 Task 16, 08 §11): stock now and its value at the base price (D94),
 * the base units sold in the range, days of cover (null = ∞), the last sale, the dead-stock flag and the range's
 * movements by type (BR-INV06).
 *
 * @phpstan-import-type InventoryRow from \App\Modules\Reports\Queries\InventoryAnalyticsQuery
 *
 * @property-read InventoryRow $resource
 */
final class InventoryAnalyticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'sku_id' => $row['sku_id'],
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'sku_code' => $row['sku_code'],
            'sku_name' => $row['sku_name'],
            'on_hand' => $row['on_hand'],
            'available' => $row['available'],
            'stock_value' => $row['stock_value'],
            'sold_base' => $row['sold_base'],
            'days_of_cover' => $row['days_of_cover'],
            'last_sold_at' => $row['last_sold_at'],
            'dead' => $row['dead'],
            'movements' => $row['movements'],
        ];
    }
}
