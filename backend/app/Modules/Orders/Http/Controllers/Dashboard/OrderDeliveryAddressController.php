<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\UpdateDeliveryAddress;
use App\Modules\Orders\Data\DeliveryAddressData;
use App\Modules\Orders\Http\Requests\UpdateDeliveryAddressRequest;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;

final readonly class OrderDeliveryAddressController
{
    /** PUT /api/dashboard/orders/{number}/delivery-address — BR-O10, D9 */
    public function update(UpdateDeliveryAddressRequest $request, Order $order, UpdateDeliveryAddress $update, OrderDetailQuery $detail, DashboardOrdersQuery $orders): DashboardOrderResource
    {
        $order = $update->handle($order, DeliveryAddressData::fromRequest($request));

        return new DashboardOrderResource($detail->forDashboard($order), $orders->staleBefore());
    }
}
