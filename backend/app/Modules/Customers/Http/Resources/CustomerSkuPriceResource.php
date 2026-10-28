<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerSkuPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A price negotiated with a customer (BR-PR13, V2 Task 11): the SKU unit it is for (D91: code, product, unit, factor and
 * the unit's own base price, to compare), the customer's `unit_price` and the merchant's note. Expects `sku.product` and
 * `skuUnit.unit` loaded (CustomerSkuPricesQuery).
 *
 * @property-read CustomerSkuPrice $resource
 */
final class CustomerSkuPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = $this->resource;
        $sku = $price->sku;
        $unit = $price->skuUnit;

        return [
            'sku_id' => $sku->id,
            'sku_unit_id' => $unit->id,
            'sku_code' => $sku->code,
            'product_name' => $sku->product->name,
            'unit' => $unit->unit->name,
            'conversion_factor' => $unit->conversion_factor,
            'base_price' => $unit->base_price,
            'unit_price' => $price->unit_price,
            'note' => $price->note,
            'updated_at' => $price->updated_at->toIso8601String(),
        ];
    }
}
