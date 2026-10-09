<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\InviteCustomer;
use App\Modules\Customers\Http\Resources\InviteResultResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\JsonResponse;

final readonly class CustomerInviteController
{
    /**
     * POST /api/dashboard/customers/{customer}/invite — BR-CU08: sends or re-sends the link. Always 200: the
     * first send creates the invitation row, but what the merchant asked for is a message, not a resource.
     */
    public function store(Customer $customer, InviteCustomer $invite): JsonResponse
    {
        return new InviteResultResource($invite->handle($customer))->response()->setStatusCode(200);
    }
}
