<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Data\PreorderFiltersData;
use App\Modules\Orders\Http\Requests\ListPreordersRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\PreordersQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class PreorderController
{
    /** GET /api/dashboard/preorders — BR-O14: the pre-orders in conversion order, with their lines */
    public function index(ListPreordersRequest $request, PreordersQuery $preorders, DashboardOrdersQuery $orders): AnonymousResourceCollection
    {
        return DashboardOrderResource::page($preorders->paginate(PreorderFiltersData::fromRequest($request), $request->perPage()), $orders->pendingClock());
    }
}
