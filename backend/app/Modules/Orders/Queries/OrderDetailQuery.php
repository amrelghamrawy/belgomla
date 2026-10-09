<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\Order;

/**
 * An order with what its detail page shows (08-api-contract.md §3, §9): lines and status history, plus the
 * customer for the dashboard. Collections arrive with Task 7; until then the dashboard lists none.
 */
final readonly class OrderDetailQuery
{
    public function forStore(Order $order): Order
    {
        return $order->load(['items', 'history']);
    }

    public function forDashboard(Order $order): Order
    {
        return $order->load(['items', 'history', 'customer']);
    }
}
