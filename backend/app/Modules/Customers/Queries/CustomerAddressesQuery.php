<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;

/** BR-CU03: a customer's active addresses, the default first, then oldest first. */
final readonly class CustomerAddressesQuery
{
    /**
     * @return Collection<int, CustomerAddress>
     */
    public function active(Customer $customer): Collection
    {
        return CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('status', AddressStatus::Active)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }
}
