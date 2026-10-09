<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store;

use App\Modules\Customers\Actions\ArchiveAddress;
use App\Modules\Customers\Actions\CreateAddress;
use App\Modules\Customers\Actions\UpdateAddress;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Http\Requests\SaveAddressRequest;
use App\Modules\Customers\Http\Resources\AddressResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Queries\CustomerAddressesQuery;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** /api/store/addresses — BR-CU03. A customer reaches only their own active addresses (CustomerAddressPolicy). */
final readonly class AddressController
{
    public function index(#[CurrentUser('customer')] Customer $customer, CustomerAddressesQuery $addresses): AnonymousResourceCollection
    {
        return AddressResource::collection($addresses->active($customer));
    }

    public function store(SaveAddressRequest $request, #[CurrentUser('customer')] Customer $customer, CreateAddress $create): JsonResponse
    {
        return new AddressResource($create->handle($customer, AddressData::fromRequest($request)))->response()->setStatusCode(201);
    }

    public function update(SaveAddressRequest $request, #[CurrentUser('customer')] Customer $customer, CustomerAddress $address, UpdateAddress $update): AddressResource
    {
        Gate::forUser($customer)->authorize('update', $address);

        return new AddressResource($update->handle($address, AddressData::fromRequest($request)));
    }

    public function destroy(#[CurrentUser('customer')] Customer $customer, CustomerAddress $address, ArchiveAddress $archive): JsonResponse
    {
        Gate::forUser($customer)->authorize('delete', $address);

        $archive->handle($address);

        return ApiResponse::noContent();
    }
}
