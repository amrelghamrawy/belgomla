<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Http\Requests\ListDashboardOrdersRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/dashboard/orders — every order of the tenant (tenant scope + `auth:tenant_user`). */
final readonly class OrderController
{
    public function index(ListDashboardOrdersRequest $request, DashboardOrdersQuery $orders): AnonymousResourceCollection
    {
        return DashboardOrderResource::page($orders->paginate(OrderFiltersData::fromRequest($request), $request->perPage()), $orders->staleBefore());
    }

    public function show(Order $order, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        return new DashboardOrderResource($detail->forDashboard($order), $orders->staleBefore());
    }
}
