<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Orders\Models\Order;

/**
 * The latest orders of a customer, newest first, for the dashboard customer page (08-api-contract.md §8).
 * Bound for Customers' RecentOrders contract in OrdersServiceProvider.
 */
final readonly class CustomerRecentOrders implements RecentOrders
{
    /**
     * @return list<array{id: int, number: int, status: string, total: string, placed_at: string}>
     */
    public function forCustomer(int $customerId, int $limit = 5): array
    {
        return array_values(Order::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'total' => $order->order_total,
                'placed_at' => $order->placed_at->toIso8601String(),
            ])
            ->all());
    }
}
