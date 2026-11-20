<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\ApplyOrderDiscount;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Http\Requests\ApplyOrderDiscountRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;

final readonly class OrderDiscountController
{
    /** PUT /api/dashboard/orders/{number}/discount — BR-PR08, D85: the order's manual discounts, answered like the order page */
    public function update(ApplyOrderDiscountRequest $request, Order $order, ApplyOrderDiscount $apply, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        $order = $apply->handle($order, DiscountData::fromRequest($request), $request->actorId());

        return new DashboardOrderResource($detail->forDashboard($order), $orders->pendingClock(), $detail->collections($order), $detail->messages($order));
    }
}
