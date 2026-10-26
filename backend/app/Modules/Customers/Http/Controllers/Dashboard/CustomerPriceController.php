<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Actions\RemoveCustomerSkuPrice;
use App\Modules\Customers\Actions\SetCustomerSkuPrice;
use App\Modules\Customers\Http\Requests\ListCustomerPricesRequest;
use App\Modules\Customers\Http\Requests\SetCustomerPriceRequest;
use App\Modules\Customers\Http\Resources\CustomerSkuPriceResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\CustomerSkuPricesQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /api/dashboard/customers/{customer}/prices[/{sku}] — BR-PR13 (V2 Task 11, D89): the prices negotiated with a customer.
 * {customer} and {sku} are tenant-scoped (BR-T04). PUT answers 201 for a new price, 200 when it replaces one.
 */
final readonly class CustomerPriceController
{
    public function index(ListCustomerPricesRequest $request, Customer $customer, CustomerSkuPricesQuery $prices): AnonymousResourceCollection
    {
        return CustomerSkuPriceResource::collection($prices->paginate($customer, $request->search(), $request->perPage()));
    }

    public function update(SetCustomerPriceRequest $request, Customer $customer, Sku $sku, SetCustomerSkuPrice $set, CustomerSkuPricesQuery $prices): CustomerSkuPriceResource
    {
        return new CustomerSkuPriceResource($prices->one($set->handle($customer, $sku, $request->unitPrice(), $request->note())));
    }

    public function destroy(Customer $customer, Sku $sku, RemoveCustomerSkuPrice $remove): JsonResponse
    {
        $remove->handle($customer, $sku);

        return ApiResponse::noContent();
    }
}
