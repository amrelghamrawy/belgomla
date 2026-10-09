<?php

declare(strict_types=1);

namespace App\Modules\Customers\Policies;

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Auth\Access\Response;

/**
 * BR-CU03: a customer manages only their own active addresses. Anyone else's address, or an archived one,
 * is reported as not found rather than forbidden, so ids reveal nothing (BR-T04).
 */
final readonly class CustomerAddressPolicy
{
    public function update(Customer $customer, CustomerAddress $address): Response
    {
        return $this->owns($customer, $address);
    }

    public function delete(Customer $customer, CustomerAddress $address): Response
    {
        return $this->owns($customer, $address);
    }

    private function owns(Customer $customer, CustomerAddress $address): Response
    {
        return $address->customer_id === $customer->id && $address->status === AddressStatus::Active
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
