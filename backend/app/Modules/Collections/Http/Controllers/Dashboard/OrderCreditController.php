<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Actions\ApplyCustomerCredit;
use App\Modules\Collections\Http\Requests\ApplyCreditRequest;
use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Collections\Queries\CustomerCreditsQuery;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\JsonResponse;

final readonly class OrderCreditController
{
    /** POST /api/dashboard/orders/{number}/apply-credit — BR-C07: answers the `credit` collection with the order's new balance */
    public function store(ApplyCreditRequest $request, Order $order, ApplyCustomerCredit $apply, CustomerCreditsQuery $credits): JsonResponse
    {
        return new CollectionResource($apply->handle($order, $credits->find($request->creditId()), $request->amount(), $request->actorId()))
            ->response()
            ->setStatusCode(201);
    }
}
