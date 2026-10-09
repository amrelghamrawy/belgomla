<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store;

use App\Modules\Customers\Actions\AddCartItem;
use App\Modules\Customers\Actions\RemoveCartItem;
use App\Modules\Customers\Actions\UpdateCartItem;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Http\Requests\AddCartItemRequest;
use App\Modules\Customers\Http\Requests\UpdateCartItemRequest;
use App\Modules\Customers\Http\Resources\CartResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Container\Attributes\CurrentUser;

/** /api/store/cart/items — BR-CU06. Every change answers with the refreshed cart. */
final readonly class CartItemController
{
    public function store(AddCartItemRequest $request, #[CurrentUser('customer')] Customer $customer, AddCartItem $add): CartResource
    {
        return new CartResource($add->handle($customer, CartLineData::fromRequest($request)));
    }

    public function update(UpdateCartItemRequest $request, #[CurrentUser('customer')] Customer $customer, int $sku, UpdateCartItem $update): CartResource
    {
        return new CartResource($update->handle($customer, $sku, $request->quantity()));
    }

    public function destroy(#[CurrentUser('customer')] Customer $customer, int $sku, RemoveCartItem $remove): CartResource
    {
        return new CartResource($remove->handle($customer, $sku));
    }
}
