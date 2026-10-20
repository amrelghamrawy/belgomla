<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\EditOrderLines;
use App\Modules\Orders\Data\OrderEditData;
use App\Modules\Orders\Http\Requests\EditOrderLinesRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;

final readonly class OrderLinesController
{
    /** PUT /api/dashboard/orders/{number}/lines — BR-O12, D87: the order's new lines, answered like the order page plus `meta.below_mov` */
    public function update(EditOrderLinesRequest $request, Order $order, EditOrderLines $edit, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        $edited = $edit->handle($order, OrderEditData::fromRequest($request), $request->actorId());
        $order = $edited->order;

        return new DashboardOrderResource($detail->forDashboard($order), $orders->pendingClock(), $detail->collections($order), $detail->messages($order))
            ->additional(['meta' => ['below_mov' => $edited->belowMov]]);
    }
}
