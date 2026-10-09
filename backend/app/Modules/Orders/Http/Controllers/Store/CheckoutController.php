<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Store;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Http\Resources\PlacedOrderResource;
use App\Modules\Orders\Queries\OrderDetailQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class CheckoutController
{
    /** POST /api/store/checkout — BR-PR06, 07-domain-model.md §12 */
    public function store(CheckoutRequest $request, #[CurrentUser('customer')] Customer $customer, Checkout $checkout, OrderDetailQuery $detail): JsonResponse
    {
        $order = $checkout->handle($customer, CheckoutData::fromRequest($request));

        return new PlacedOrderResource($detail->forStore($order))->response()->setStatusCode(201);
    }
}
