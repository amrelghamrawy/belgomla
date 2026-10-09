<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;
use App\Modules\Customers\Services\OrderableQuantity;

/**
 * BR-CU06: changes the quantity of a SKU already in the cart (`404` when it is not), with the same checks as
 * adding it (OrderableQuantity). Returns the refreshed cart.
 */
final readonly class UpdateCartItem
{
    public function __construct(
        private CartSkuQuery $skus,
        private OrderableQuantity $orderable,
        private RefreshCart $refresh,
    ) {}

    public function handle(Customer $customer, int $skuId, int $quantity): PricedCart
    {
        $item = CartItem::query()
            ->tap(new InCartOf($customer))
            ->where('sku_id', $skuId)
            ->firstOrFail();

        $this->orderable->assert($this->skus->find($skuId), $quantity);

        $item->forceFill(['quantity' => $quantity])->save();

        return $this->refresh->handle($customer);
    }
}
