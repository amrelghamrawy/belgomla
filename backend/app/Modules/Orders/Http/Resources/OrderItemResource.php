<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order line exactly as snapshotted at checkout (BR-PR07): quantity in selling units, prices as strings; the
 * price before a manual discount (`list_unit_price`), the discount itself (`manual_discount`, null for none) and the
 * price charged (`unit_price`, D85).
 *
 * @property-read OrderItem $resource
 */
final class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id, // the line a discount names (D85)
            'sku_id' => $item->sku_id,
            'product_name' => $item->product_name,
            'sku_code' => $item->sku_code,
            'sku_name' => $item->sku_name,
            'unit' => $item->unit_name,
            'conversion_factor' => $item->conversion_factor,
            'quantity' => $item->quantity,
            'cover_price' => $item->cover_price,
            'list_unit_price' => $item->list_unit_price,
            'manual_discount' => $item->manual_discount_type === null ? null : ['type' => $item->manual_discount_type->value, 'value' => $item->manual_discount_value],
            'unit_price' => $item->unit_price,
            'discount_percent' => $item->discount_percent,
            'tier_min_quantity' => $item->tier_min_quantity,
            'line_total' => $item->line_total,
        ];
    }
}
