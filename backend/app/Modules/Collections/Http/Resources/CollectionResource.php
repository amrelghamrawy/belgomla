<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Resources;

use App\Modules\Collections\Models\CashCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A collection record (08-api-contract.md §10, BR-C02). `order` carries the balance after this change, so the
 * dashboard refreshes the order's collected amount and status from a record/void answer; `customer` appears when
 * the order's customer is loaded (the collections list), not on the order detail.
 *
 * @property-read CashCollection $resource
 */
final class CollectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $collection = $this->resource;
        $order = $collection->order;

        return [
            'id' => $collection->id,
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'order_total' => $order->order_total,
                'collected_amount' => $order->collected_amount,
                'collection_status' => $order->collection_status->value,
            ],
            'customer' => $this->when($order->relationLoaded('customer'), fn (): array => [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
            ]),
            'amount' => $collection->amount,
            'method' => $collection->method->value,
            'method_label' => $collection->method->label(),
            'collected_at' => $collection->collected_at->toIso8601String(),
            'recorded_by' => $collection->recorded_by,
            'note' => $collection->note,
            'is_voided' => $collection->voided_at !== null,
            'voided_at' => $collection->voided_at?->toIso8601String(),
            'void_reason' => $collection->void_reason,
            'voided_by' => $collection->voided_by,
            'created_at' => $collection->created_at->toIso8601String(),
        ];
    }
}
