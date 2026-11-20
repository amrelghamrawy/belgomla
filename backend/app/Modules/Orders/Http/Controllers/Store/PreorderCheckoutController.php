<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Store;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Actions\PlacePreorder;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Http\Resources\PlacedOrderResource;
use App\Modules\Orders\Queries\OrderDetailQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class PreorderCheckoutController
{
    /** POST /api/store/checkout/preorder — BR-O14: the cart's pre-order lines become a pre-order */
    public function store(CheckoutRequest $request, #[CurrentUser('customer')] Customer $customer, PlacePreorder $place, OrderDetailQuery $detail): JsonResponse
    {
        $order = $place->handle($customer, CheckoutData::fromRequest($request));

        return new PlacedOrderResource($detail->forStore($order))->response()->setStatusCode(201);
    }
}
