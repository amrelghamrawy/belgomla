<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Services\OrderableQuantity;

/**
 * BR-CU06: puts a SKU unit in the customer's cart with the given quantity, replacing the quantity when the unit is
 * already there (upsert), as an order line or a pre-order line (BR-O14; the two kinds of one unit are separate lines, and
 * so are two units of one SKU, D91). A line naming only the SKU is in its default unit. The unit must belong to the tenant
 * (`404`, BR-T04) and the quantity be orderable now next to the SKU's other units in the cart (OrderableQuantity).
 * Returns the refreshed cart.
 */
final readonly class AddCartItem
{
    public function __construct(
        private CartSkuQuery $skus,
        private OrderableQuantity $orderable,
        private CartQuery $cart,
        private RefreshCart $refresh,
    ) {}

    public function handle(Customer $customer, CartLineData $line): PricedCart
    {
        $unit = $line->skuUnitId !== null ? $this->skus->find($line->skuUnitId) : $this->skus->findDefault($line->skuId ?? 0);
        $this->orderable->assert($unit, $line->quantity, $line->kind, $this->cart->heldBesides($customer, $unit, $line->kind));

        $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);
        CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'sku_unit_id' => $unit->id, 'kind' => $line->kind], ['sku_id' => $unit->sku_id, 'quantity' => $line->quantity]);

        return $this->refresh->handle($customer);
    }
}
