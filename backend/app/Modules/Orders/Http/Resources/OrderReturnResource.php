<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A return on the dashboard (08-api-contract.md §9, BR-R01..R06), voided ones included and marked. `order` carries
 * the order's balance after this change (like a collection, so a record/void answer refreshes the order) and appears
 * when the order is loaded (the list and the writes, not the order detail); `customer` when the order's customer is
 * loaded (the list). Lines show the order line's snapshot names.
 *
 * @property-read OrderReturn $resource
 */
final class OrderReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $return = $this->resource;

        return [
            'id' => $return->id,
            'number' => $return->number,
            'order' => $this->whenLoaded('order', fn (): array => [
                'id' => $return->order->id,
                'number' => $return->order->number,
                'order_total' => $return->order->order_total,
                'returned_total' => $return->order->returned_total,
                'net_total' => $return->order->netTotal(),
                'collected_amount' => $return->order->collected_amount,
                'collection_status' => $return->order->collection_status->value,
            ]),
            'customer' => $this->when($return->relationLoaded('order') && $return->order->relationLoaded('customer'), fn (): array => [
                'id' => $return->order->customer->id,
                'name' => $return->order->customer->name,
            ]),
            'total' => $return->total,
            'refund_amount' => $return->refund_amount,
            'refund_method' => $return->refund_method?->value,
            'reason' => $return->reason,
            'returned_at' => $return->returned_at->toIso8601String(),
            'recorded_by' => $return->recorded_by,
            'is_voided' => $return->voided_at !== null,
            'voided_at' => $return->voided_at?->toIso8601String(),
            'void_reason' => $return->void_reason,
            'voided_by' => $return->voided_by,
            'items' => $this->whenLoaded('items', fn (): array => $return->items->map(fn (OrderReturnItem $item): array => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->orderItem->product_name,
                'sku_code' => $item->orderItem->sku_code,
                'unit' => $item->orderItem->unit_name,
                'quantity' => $item->quantity,
                'quantity_base' => $item->quantity_base,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->values()->all()),
            'created_at' => $return->created_at->toIso8601String(),
        ];
    }
}
