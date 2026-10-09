<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\OrderableQuantity;

/**
 * BR-CU06: puts a SKU in the customer's cart with the given quantity, replacing the quantity when the SKU is
 * already there (upsert). The SKU must belong to the tenant (`404`, BR-T04) and the quantity be orderable now
 * (OrderableQuantity). Returns the refreshed cart.
 */
final readonly class AddCartItem
{
    public function __construct(
        private CartSkuQuery $skus,
        private OrderableQuantity $orderable,
        private RefreshCart $refresh,
    ) {}

    public function handle(Customer $customer, CartLineData $line): PricedCart
    {
        $sku = $this->skus->find($line->skuId);
        $this->orderable->assert($sku, $line->quantity);

        $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);
        CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'sku_id' => $sku->id], ['quantity' => $line->quantity]);

        return $this->refresh->handle($customer);
    }
}
