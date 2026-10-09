<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order line of a customer statement (08-api-contract.md §10): its total, what was collected and the balance
 * left (`order_total − collected_amount`, whatever the status; the summary decides what counts as outstanding, D59).
 *
 * @property-read Order $resource
 */
final class StatementOrderResource extends JsonResource
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
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'order_total' => $order->order_total,
            'collected_amount' => $order->collected_amount,
            'balance' => Money::subtract($order->order_total, $order->collected_amount),
            'collection_status' => $order->collection_status->value,
        ];
    }
}
