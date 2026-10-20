<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\PlaceOrderForCustomer;
use App\Modules\Orders\Data\MerchantOrderData;
use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Http\Requests\ListDashboardOrdersRequest;
use App\Modules\Orders\Http\Requests\StoreMerchantOrderRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/dashboard/orders — every order of the tenant (tenant scope + `auth:tenant_user`), and orders placed for a customer. */
final readonly class OrderController
{
    public function index(ListDashboardOrdersRequest $request, DashboardOrdersQuery $orders): AnonymousResourceCollection
    {
        return DashboardOrderResource::page($orders->paginate(OrderFiltersData::fromRequest($request), $request->perPage()), $orders->pendingClock());
    }

    /** POST /api/dashboard/orders — BR-O11: an order placed for a customer, answered like the order page */
    public function store(StoreMerchantOrderRequest $request, PlaceOrderForCustomer $place, OrderDetailQuery $detail, DashboardOrdersQuery $orders): JsonResponse
    {
        $order = $place->handle(MerchantOrderData::fromRequest($request), $request->actorId());

        return new DashboardOrderResource($detail->forDashboard($order), $orders->pendingClock(), $detail->collections($order), $detail->messages($order))->response()->setStatusCode(201);
    }

    public function show(Order $order, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        return new DashboardOrderResource($detail->forDashboard($order), $orders->pendingClock(), $detail->collections($order), $detail->messages($order));
    }
}
