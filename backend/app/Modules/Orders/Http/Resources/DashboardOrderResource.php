<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * An order on the dashboard (08-api-contract.md §9): the customer's shape plus the order id, the customer, the
 * status timestamps, `is_stale` (pending past `stale_pending_hours`, BR-INV07), history with the actor id and
 * note, and `collections` on the detail page (shaped by Collections through OrderDetailQuery::collections()).
 *
 * @property-read Order $resource
 */
final class DashboardOrderResource extends JsonResource
{
    /**
     * @param  list<JsonResource>  $collections
     */
    public function __construct(Order $resource, private readonly CarbonImmutable $staleBefore, private readonly array $collections = [])
    {
        parent::__construct($resource);
    }

    /**
     * @param  LengthAwarePaginator<int, Order>  $orders
     */
    public static function page(LengthAwarePaginator $orders, CarbonImmutable $staleBefore): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection($orders->through(fn (Order $order): self => new self($order, $staleBefore)), self::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $detail = $order->relationLoaded('items');

        return [
            'id' => $order->id,
            ...new OrderResource($order)->toArray($request),
            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
            ]),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'is_stale' => $order->status === OrderStatus::Pending && $order->placed_at->lessThan($this->staleBefore),
            'history' => $this->whenLoaded('history', fn (): array => $order->history->map(fn (OrderStatusHistory $entry): array => [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'at' => $entry->created_at->toIso8601String(),
                'by' => $entry->actor_type->value,
                'actor_id' => $entry->actor_id,
                'note' => $entry->note,
            ])->values()->all()),
            'collections' => $this->when($detail, $this->collections),
        ];
    }
}
