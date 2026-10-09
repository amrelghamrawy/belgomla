<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Http\Requests\TransitionOrderRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;

final readonly class OrderTransitionController
{
    /** POST /api/dashboard/orders/{number}/transition — BR-O01, BR-INV05, BR-C04 */
    public function store(TransitionOrderRequest $request, Order $order, TransitionOrder $transition, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        $order = $transition->handle($order, TransitionData::fromRequest($request), $request->actorId());

        return new DashboardOrderResource($detail->forDashboard($order), $orders->staleBefore(), $detail->collections($order));
    }
}
