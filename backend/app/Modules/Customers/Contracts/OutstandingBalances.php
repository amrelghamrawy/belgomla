<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/**
 * What each customer still owes (BR-C03, D5 b): delivered order totals minus their non-voided collections.
 * Collections implements it in Task 7; until then nobody owes anything (NullOutstandingBalances).
 */
interface OutstandingBalances
{
    /**
     * @param  list<int>  $customerIds
     * @return array<int, string> customer id => amount ("150.00"); a customer missing from the map owes "0.00"
     */
    public function forCustomers(array $customerIds): array;
}
