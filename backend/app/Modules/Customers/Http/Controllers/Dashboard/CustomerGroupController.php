<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\CreateCustomerGroup;
use App\Modules\Customers\Actions\UpdateCustomerGroup;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Http\Requests\StoreCustomerGroupRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerGroupRequest;
use App\Modules\Customers\Http\Resources\CustomerGroupResource;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Queries\CustomerGroupsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/dashboard/customer-groups — BR-PR12 (V2 Task 11). {group} is tenant-scoped (BR-T04). */
final readonly class CustomerGroupController
{
    public function index(CustomerGroupsQuery $groups): AnonymousResourceCollection
    {
        return CustomerGroupResource::collection($groups->get());
    }

    public function store(StoreCustomerGroupRequest $request, CreateCustomerGroup $create, CustomerGroupsQuery $groups): JsonResponse
    {
        return new CustomerGroupResource($groups->withCount($create->handle(CustomerGroupData::fromRequest($request))))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerGroupRequest $request, CustomerGroup $group, UpdateCustomerGroup $update, CustomerGroupsQuery $groups): CustomerGroupResource
    {
        return new CustomerGroupResource($groups->withCount($update->handle($group, CustomerGroupData::fromRequest($request))));
    }
}
