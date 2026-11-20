<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Data\ReturnFiltersData;
use App\Modules\Orders\Http\Requests\ListReturnsRequest;
use App\Modules\Orders\Http\Resources\OrderReturnResource;
use App\Modules\Orders\Queries\ReturnsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ReturnController
{
    /** GET /api/dashboard/returns?from=&to=&customer_id=&q= — every return of the tenant (BR-R05) */
    public function index(ListReturnsRequest $request, ReturnsQuery $returns): AnonymousResourceCollection
    {
        return OrderReturnResource::collection($returns->paginate(ReturnFiltersData::fromRequest($request), $request->perPage()));
    }
}
