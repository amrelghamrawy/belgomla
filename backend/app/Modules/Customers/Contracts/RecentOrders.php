<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/**
 * The latest orders of a customer for the dashboard customer page (08-api-contract.md §8). Implemented by
 * Orders (CustomerRecentOrders, bound in OrdersServiceProvider), so Customers never depends on Orders.
 */
interface RecentOrders
{
    /**
     * @return list<array{id: int, number: int, status: string, total: string, placed_at: string}>
     */
    public function forCustomer(int $customerId, int $limit = 5): array;
}
