<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\OutstandingBalances;

/** Until Collections (Task 7) rebinds OutstandingBalances, every customer owes "0.00". */
final readonly class NullOutstandingBalances implements OutstandingBalances
{
    public function forCustomers(array $customerIds): array
    {
        return [];
    }
}
