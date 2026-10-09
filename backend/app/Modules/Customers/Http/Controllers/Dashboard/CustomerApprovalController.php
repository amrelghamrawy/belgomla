<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\ApproveCustomer;
use App\Modules\Customers\Http\Requests\ApproveCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\OutstandingAmountsQuery;

final readonly class CustomerApprovalController
{
    /** POST /api/dashboard/customers/{customer}/approve — D2: pending_approval → active */
    public function store(ApproveCustomerRequest $request, Customer $customer, ApproveCustomer $approve, OutstandingAmountsQuery $outstanding): CustomerResource
    {
        return CustomerResource::forDashboard($approve->handle($customer, $request->actorId()), $outstanding->of($customer));
    }
}
