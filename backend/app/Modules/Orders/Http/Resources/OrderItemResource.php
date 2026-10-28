<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order line exactly as snapshotted at checkout (BR-PR07): quantity in selling units, prices as strings; the
 * price before a manual discount (`list_unit_price`), the discount itself (`manual_discount`, null for none) and the
 * price charged (`unit_price`, D85). `delivered_quantity` is set on delivery (less than `quantity` when delivered short,
 * BR-O13; `line_total` charges it) and `removed_at` when the merchant removed the line (BR-O12, out of every total).
 * `converted_quantity` is what of a pre-order line already went into a normal order (`line_total` charges the rest,
 * BR-O14); 0 on a normal order. `price_source` says where the list price came from: base, tier, the customer's group
 * or their negotiated price (BR-PR14, V2 Task 11).
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
            'sku_unit_id' => $item->sku_unit_id,
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
            'price_source' => $item->price_source->value,
            'line_total' => $item->line_total,
            'delivered_quantity' => $item->delivered_quantity,
            'removed_at' => $item->removed_at?->toIso8601String(),
            'converted_quantity' => $item->converted_quantity,
        ];
    }
}
