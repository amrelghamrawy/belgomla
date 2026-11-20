<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Services\OrderableQuantity;

/**
 * BR-CU06: changes the quantity of a SKU unit already in the cart as that kind of line (`404` when it is not), with the
 * same checks as adding it (OrderableQuantity, next to the SKU's other units in the cart, D91). Returns the refreshed cart.
 */
final readonly class UpdateCartItem
{
    public function __construct(
        private CartSkuQuery $skus,
        private OrderableQuantity $orderable,
        private CartQuery $cart,
        private RefreshCart $refresh,
    ) {}

    public function handle(Customer $customer, int $skuUnitId, int $quantity, CartLineKind $kind = CartLineKind::Order): PricedCart
    {
        $item = CartItem::query()
            ->tap(new InCartOf($customer))
            ->where('sku_unit_id', $skuUnitId)
            ->where('kind', $kind)
            ->firstOrFail();

        $unit = $this->skus->find($skuUnitId);
        $this->orderable->assert($unit, $quantity, $kind, $this->cart->heldBesides($customer, $unit, $kind));

        $item->forceFill(['quantity' => $quantity])->save();

        return $this->refresh->handle($customer);
    }
}
