<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Actions\VoidCustomerCredit;
use App\Modules\Collections\Http\Requests\VoidCollectionRequest;
use App\Modules\Collections\Http\Resources\CustomerCreditResource;
use App\Modules\Collections\Models\CustomerCredit;

final readonly class CustomerCreditVoidController
{
    /** POST /api/dashboard/credits/{id}/void — BR-C07: only while nothing of it was applied */
    public function store(VoidCollectionRequest $request, CustomerCredit $customerCredit, VoidCustomerCredit $void): CustomerCreditResource
    {
        return new CustomerCreditResource($void->handle($customerCredit, $request->reason(), $request->actorId()));
    }
}
