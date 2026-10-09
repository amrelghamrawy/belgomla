<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Services\CartPricer;

/**
 * Loads the cart for display (BR-CU06): lines whose product is no longer active are deleted and reported in
 * `removed_items` so the customer is told once (BR-P04); the rest are priced now (CartPricer). The deletes
 * make this a write, hence an Action.
 */
final readonly class RefreshCart
{
    public function __construct(
        private CartQuery $cart,
        private CartPricer $pricer,
    ) {}

    public function handle(Customer $customer): PricedCart
    {
        $lines = $this->cart->linesOf($customer);
        $orderable = $lines->filter(fn (CartItem $item): bool => $item->sku->product->status === ProductStatus::Active);
        $gone = $lines->diff($orderable);

        $removed = [];

        foreach ($gone as $item) {
            $removed[] = ['product_name' => $item->sku->product->name, 'reason' => 'inactive'];
        }

        if ($gone->isNotEmpty()) {
            CartItem::query()->whereKey($gone->modelKeys())->delete();
        }

        return $this->pricer->price($orderable->values(), $removed);
    }
}
