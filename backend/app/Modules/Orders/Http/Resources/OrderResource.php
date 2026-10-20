<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the customer sees it (08-api-contract.md §3). The list leaves out `items`, `history` and `returns`; the
 * detail loads them (OrderDetailQuery; the customer sees live returns only, without the merchant's reason, BR-R05).
 * `net_total` = order_total − returned_total, `order_total` = items_total − the order discount (`discount`, null for
 * none, without the merchant's reason, D85) (BR-O08). The detail lists every line, removed ones too (BR-O12), and
 * `changes[]`, the line changes after placement (BR-O12, BR-O13). DashboardOrderResource builds on this shape.
 *
 * @property-read Order $resource
 */
final class OrderResource extends JsonResource
{
    /**
     * BR-O12, BR-O13: the order's line changes, oldest first; the dashboard adds who made each.
     *
     * @return list<array<string, int|string|null>>
     */
    public static function changes(Order $order, bool $withActor): array
    {
        return array_values($order->itemChanges->map(fn (OrderItemChange $change): array => [
            'order_item_id' => $change->order_item_id,
            'product_name' => $change->orderItem?->product_name,
            'sku_code' => $change->orderItem?->sku_code,
            'change' => $change->change->value,
            'old_quantity' => $change->old_quantity,
            'new_quantity' => $change->new_quantity,
            'unit_price_before' => $change->unit_price_before,
            'unit_price_after' => $change->unit_price_after,
            'at' => $change->created_at->toIso8601String(),
            ...($withActor ? ['actor_id' => $change->actor_id] : []),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'placed_at' => $order->placed_at->toIso8601String(),
            'items_total' => $order->items_total,
            'discount' => $order->discount_type === null ? null : ['type' => $order->discount_type->value, 'value' => $order->discount_value, 'amount' => $order->discount_amount],
            'order_total' => $order->order_total,
            'returned_total' => $order->returned_total,
            'net_total' => $order->netTotal(),
            'collection_status' => $order->collection_status->value,
            'collected_amount' => $order->collected_amount,
            'delivery' => [
                'name' => $order->delivery_name,
                'phone' => $order->delivery_phone,
                'address' => $order->delivery_address,
            ],
            'customer_note' => $order->customer_note,
            'cancel_reason' => $order->cancel_reason,
            'cancelled_by' => $order->cancelled_by_type?->value,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'changes' => $this->whenLoaded('itemChanges', fn (): array => self::changes($order, withActor: false)),
            // The customer sees status changes only; a return's note keeps the status (BR-R05) and stays on the dashboard.
            'history' => $this->whenLoaded('history', fn (): array => $order->history->filter(fn (OrderStatusHistory $entry): bool => $entry->from_status !== $entry->to_status)->map(fn (OrderStatusHistory $entry): array => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'at' => $entry->created_at->toIso8601String(),
                'by' => $entry->actor_type->value,
            ])->values()->all()),
            'returns' => $this->whenLoaded('returns', fn (): array => $order->returns->map(fn (OrderReturn $return): array => [
                'number' => $return->number,
                'total' => $return->total,
                'refund_amount' => $return->refund_amount,
                'refund_method' => $return->refund_method?->value,
                'returned_at' => $return->returned_at->toIso8601String(),
                'items' => $return->items->map(fn (OrderReturnItem $item): array => [
                    'product_name' => $item->orderItem->product_name,
                    'sku_code' => $item->orderItem->sku_code,
                    'unit' => $item->orderItem->unit_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ])->values()->all(),
            ])->values()->all()),
        ];
    }
}
