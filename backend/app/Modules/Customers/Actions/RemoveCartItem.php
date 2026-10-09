<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;

/** BR-CU06: takes a SKU out of the cart (`404` when it is not there). Returns the refreshed cart. */
final readonly class RemoveCartItem
{
    public function __construct(private RefreshCart $refresh) {}

    public function handle(Customer $customer, int $skuId): PricedCart
    {
        CartItem::query()
            ->tap(new InCartOf($customer))
            ->where('sku_id', $skuId)
            ->firstOrFail()
            ->delete();

        return $this->refresh->handle($customer);
    }
}
