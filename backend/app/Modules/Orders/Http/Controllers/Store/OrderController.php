<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Store;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\CustomerOrdersQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** /api/store/orders — the customer's own orders only (OrderPolicy, BR-CU02). */
final readonly class OrderController
{
    public function index(ListOrdersRequest $request, #[CurrentUser('customer')] Customer $customer, CustomerOrdersQuery $orders): AnonymousResourceCollection
    {
        return OrderResource::collection($orders->paginate($customer, $request->status(), $request->perPage()));
    }

    public function show(#[CurrentUser('customer')] Customer $customer, Order $order, OrderDetailQuery $detail): OrderResource
    {
        Gate::forUser($customer)->authorize('view', $order);

        return new OrderResource($detail->forStore($order));
    }
}
