<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Contracts\OrderCollections;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order with what its detail page shows (08-api-contract.md §3, §9): lines, status history and returns (live
 * ones for the customer, all for the merchant, BR-R05), plus the customer and its collection records (through OrderCollections, filled by Collections) for the dashboard.
 */
final readonly class OrderDetailQuery
{
    public function __construct(private OrderCollections $collections) {}

    public function forStore(Order $order): Order
    {
        return $order->load(['items', 'history', 'returns' => fn (HasMany $returns): HasMany => $returns->whereNull('voided_at'), 'returns.items.orderItem']);
    }

    public function forDashboard(Order $order): Order
    {
        return $order->load(['items', 'history', 'customer', 'returns.items.orderItem']);
    }

    /**
     * @return list<JsonResource>
     */
    public function collections(Order $order): array
    {
        return $this->collections->forOrder($order);
    }
}
