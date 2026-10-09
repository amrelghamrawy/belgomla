<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\UnblockCustomer;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\OutstandingAmountsQuery;

final readonly class CustomerUnblockController
{
    /** POST /api/dashboard/customers/{customer}/unblock — BR-CU05: back to the status before the block */
    public function store(Customer $customer, UnblockCustomer $unblock, OutstandingAmountsQuery $outstanding): CustomerResource
    {
        return CustomerResource::forDashboard($unblock->handle($customer), $outstanding->of($customer));
    }
}
