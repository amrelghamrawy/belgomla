<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dashboard customer page (08-api-contract.md §8): the customer with their active addresses (default first),
 * and their latest orders through the RecentOrders contract that Orders fills in Task 6.
 */
final readonly class CustomerDetailQuery
{
    public function __construct(private RecentOrders $orders) {}

    public function get(Customer $customer): Customer
    {
        return $customer->load(['addresses' => fn (HasMany $addresses): HasMany => $addresses->where('status', AddressStatus::Active)]);
    }

    /**
     * @return list<array{id: int, number: int, status: string, total: string, placed_at: string}>
     */
    public function recentOrders(Customer $customer): array
    {
        return $this->orders->forCustomer($customer->id);
    }
}
