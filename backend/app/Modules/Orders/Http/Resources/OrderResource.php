<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the customer sees it (08-api-contract.md §3). The list leaves out `items` and `history`; the detail
 * loads them (OrderDetailQuery). DashboardOrderResource builds on this shape.
 *
 * @property-read Order $resource
 */
final class OrderResource extends JsonResource
{
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
            'order_total' => $order->order_total,
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
            'history' => $this->whenLoaded('history', fn (): array => $order->history->map(fn (OrderStatusHistory $entry): array => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'at' => $entry->created_at->toIso8601String(),
                'by' => $entry->actor_type->value,
            ])->values()->all()),
        ];
    }
}
