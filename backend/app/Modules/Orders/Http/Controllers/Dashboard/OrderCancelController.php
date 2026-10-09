<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;

final readonly class OrderCancelController
{
    /** POST /api/dashboard/orders/{number}/cancel — BR-O02, BR-O03, BR-INV04 */
    public function store(CancelOrderRequest $request, Order $order, CancelOrder $cancel, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        $order = $cancel->handle($order, CancelData::fromRequest($request), CancelledBy::TenantUser, $request->actorId());

        return new DashboardOrderResource($detail->forDashboard($order), $orders->staleBefore());
    }
}
