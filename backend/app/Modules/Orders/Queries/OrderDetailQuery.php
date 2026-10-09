<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Contracts\OrderCollections;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order with what its detail page shows (08-api-contract.md §3, §9): lines and status history, plus the
 * customer and its collection records (through OrderCollections, filled by Collections) for the dashboard.
 */
final readonly class OrderDetailQuery
{
    public function __construct(private OrderCollections $collections) {}

    public function forStore(Order $order): Order
    {
        return $order->load(['items', 'history']);
    }

    public function forDashboard(Order $order): Order
    {
        return $order->load(['items', 'history', 'customer']);
    }

    /**
     * @return list<JsonResource>
     */
    public function collections(Order $order): array
    {
        return $this->collections->forOrder($order);
    }
}
