<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Catalog\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One SKU of the low-stock report (08-api-contract.md §11, D5 c). Quantities are base units (BR-U03); `threshold`
 * is MOQ × conversion factor of the SKU's smallest active unit (D36, V2 Task 13) and a SKU without a stock row has 0
 * available. Expects `product`, `inventory` and `smallestUnit` loaded.
 *
 * @property-read Sku $resource
 */
final class LowStockSkuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sku = $this->resource;
        $unit = $sku->smallestUnit;

        return [
            'sku_id' => $sku->id,
            'product_id' => $sku->product_id,
            'product_name' => $sku->product->name,
            'code' => $sku->code,
            'name' => $sku->name,
            'available' => $sku->inventory->available ?? 0,
            'moq' => $unit?->moq,
            'conversion_factor' => $unit?->conversion_factor,
            'threshold' => $unit === null ? 0 : $unit->moq * $unit->conversion_factor,
        ];
    }
}
