<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store;

use App\Modules\Customers\Actions\ClearCart;
use App\Modules\Customers\Actions\RefreshCart;
use App\Modules\Customers\Http\Resources\CartResource;
use App\Modules\Customers\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class CartController
{
    /** GET /api/store/cart — BR-CU06: live prices, removed inactive lines (BR-P04), totals and the MOV check */
    public function show(#[CurrentUser('customer')] Customer $customer, RefreshCart $refresh): CartResource
    {
        return new CartResource($refresh->handle($customer));
    }

    /** DELETE /api/store/cart */
    public function destroy(#[CurrentUser('customer')] Customer $customer, ClearCart $clear): JsonResponse
    {
        $clear->handle($customer);

        return ApiResponse::noContent();
    }
}
