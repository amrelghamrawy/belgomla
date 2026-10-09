<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

/** GET /api/store/orders: one customer's orders, newest first, optionally of one status (08-api-contract.md §3). */
final readonly class CustomerOrdersQuery
{
    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(Customer $customer, ?OrderStatus $status, int $perPage): LengthAwarePaginator
    {
        $query = Order::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('placed_at')
            ->orderByDesc('id');

        if ($status instanceof OrderStatus) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
}
