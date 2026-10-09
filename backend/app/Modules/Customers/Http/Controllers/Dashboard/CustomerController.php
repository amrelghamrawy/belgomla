<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\CreateCustomerByMerchant;
use App\Modules\Customers\Actions\UpdateCustomerByMerchant;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Http\Requests\ListCustomersRequest;
use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\CustomerDetailQuery;
use App\Modules\Customers\Queries\CustomerListQuery;
use App\Modules\Customers\Queries\OutstandingAmountsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/dashboard/customers — 08-api-contract.md §8. {customer} is tenant-scoped (BR-T04, BR-CU02). */
final readonly class CustomerController
{
    public function index(ListCustomersRequest $request, CustomerListQuery $customers, OutstandingAmountsQuery $outstanding): AnonymousResourceCollection
    {
        $page = $customers->paginate($request->search(), $request->status(), $request->perPage());

        return CustomerResource::dashboardPage($page, $outstanding->ofPage($page));
    }

    /** BR-CU08: the new customer is `invited`. */
    public function store(StoreCustomerRequest $request, CreateCustomerByMerchant $create, CustomerDetailQuery $detail, OutstandingAmountsQuery $outstanding): JsonResponse
    {
        $customer = $detail->get($create->handle(CustomerData::fromRequest($request), $request->actorId()));

        return CustomerResource::forDashboard($customer, $outstanding->of($customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer, CustomerDetailQuery $detail, OutstandingAmountsQuery $outstanding): CustomerResource
    {
        return CustomerResource::forDashboard($detail->get($customer), $outstanding->of($customer), $detail->recentOrders($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomerByMerchant $update, CustomerDetailQuery $detail, OutstandingAmountsQuery $outstanding): CustomerResource
    {
        $customer = $detail->get($update->handle($customer, CustomerData::fromRequest($request)));

        return CustomerResource::forDashboard($customer, $outstanding->of($customer));
    }
}
