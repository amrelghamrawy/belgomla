<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/**
 * The latest orders of a customer for the dashboard customer page (08-api-contract.md §8). Orders implements it
 * in Task 6; until then a customer has none (NullRecentOrders).
 */
interface RecentOrders
{
    /**
     * @return list<array{id: int, number: int, status: string, total: string, placed_at: string}>
     */
    public function forCustomer(int $customerId, int $limit = 5): array;
}
