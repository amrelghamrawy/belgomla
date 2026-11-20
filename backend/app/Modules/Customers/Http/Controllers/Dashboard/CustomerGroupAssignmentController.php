<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\AssignCustomersToGroup;
use App\Modules\Customers\Data\GroupAssignmentData;
use App\Modules\Customers\Http\Requests\AssignCustomersRequest;
use App\Modules\Customers\Models\CustomerGroup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class CustomerGroupAssignmentController
{
    /** POST /api/dashboard/customer-groups/{group}/assign — BR-PR12: by ids or by the customers list's filter → `{ assigned }`. */
    public function store(AssignCustomersRequest $request, CustomerGroup $group, AssignCustomersToGroup $assign): JsonResponse
    {
        return ApiResponse::data(['assigned' => $assign->handle($group, GroupAssignmentData::fromRequest($request))]);
    }
}
