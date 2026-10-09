<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\BlockCustomer;
use App\Modules\Customers\Http\Requests\BlockCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\OutstandingAmountsQuery;

final readonly class CustomerBlockController
{
    /** POST /api/dashboard/customers/{customer}/block — BR-CU05 */
    public function store(BlockCustomerRequest $request, Customer $customer, BlockCustomer $block, OutstandingAmountsQuery $outstanding): CustomerResource
    {
        return CustomerResource::forDashboard($block->handle($customer, $request->reason()), $outstanding->of($customer));
    }
}
