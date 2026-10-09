<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Store;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\OrderDetailQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;

final readonly class OrderCancelController
{
    /** POST /api/store/orders/{number}/cancel — BR-O02: only while pending. */
    public function store(CancelOrderRequest $request, #[CurrentUser('customer')] Customer $customer, Order $order, CancelOrder $cancel, OrderDetailQuery $detail): OrderResource
    {
        Gate::forUser($customer)->authorize('cancel', $order);

        $order = $cancel->handle($order, CancelData::fromRequest($request), CancelledBy::Customer, $customer->id);

        return new OrderResource($detail->forStore($order));
    }
}
