<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The `outstanding_amount` shown next to customers on the dashboard (08-api-contract.md §8, D5 b), read
 * through the OutstandingBalances contract that Collections fills in Task 7. Unknown customers owe "0.00".
 */
final readonly class OutstandingAmountsQuery
{
    public function __construct(private OutstandingBalances $balances) {}

    public function of(Customer $customer): string
    {
        return $this->balances->forCustomers([$customer->id])[$customer->id] ?? '0.00';
    }

    /**
     * @param  LengthAwarePaginator<int, Customer>  $customers
     * @return array<int, string>
     */
    public function ofPage(LengthAwarePaginator $customers): array
    {
        $ids = [];

        foreach ($customers->items() as $customer) {
            $ids[] = $customer->id;
        }

        return $this->balances->forCustomers($ids);
    }
}
