<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\RecentOrders;

/** Until Orders (Task 6) rebinds RecentOrders, a customer has no orders. */
final readonly class NullRecentOrders implements RecentOrders
{
    public function forCustomer(int $customerId, int $limit = 5): array
    {
        return [];
    }
}
