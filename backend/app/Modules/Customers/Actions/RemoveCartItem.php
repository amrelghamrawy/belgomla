<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;

/** BR-CU06: takes a SKU unit's order or pre-order line out of the cart (`404` when it is not there). Returns the refreshed cart. */
final readonly class RemoveCartItem
{
    public function __construct(private RefreshCart $refresh) {}

    public function handle(Customer $customer, int $skuUnitId, CartLineKind $kind = CartLineKind::Order): PricedCart
    {
        CartItem::query()
            ->tap(new InCartOf($customer))
            ->where('sku_unit_id', $skuUnitId)
            ->where('kind', $kind)
            ->firstOrFail()
            ->delete();

        return $this->refresh->handle($customer);
    }
}
