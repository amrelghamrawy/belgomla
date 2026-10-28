<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Queries\PricingContextQuery;
use App\Modules\Customers\Services\CartPricer;
use App\Modules\Customers\Services\OrderableQuantity;

/**
 * Loads the cart for display (BR-CU06): order lines whose product is no longer active, or whose unit is no longer sold
 * (D91), are deleted and reported in `removed_items` (`inactive`, BR-P04), and so are pre-order lines whose product no longer takes pre-orders
 * (`preorder_closed`: it went active or inactive, or the merchant turned pre-orders off, BR-O14), so the customer is told
 * once; the rest are priced now at the customer's own prices (CartPricer, PricingContextQuery, BR-PR14), the two kinds apart. The deletes make this a write, hence an Action.
 */
final readonly class RefreshCart
{
    public function __construct(
        private CartQuery $cart,
        private CartPricer $pricer,
        private PricingContextQuery $pricing,
        private OrderableQuantity $orderable,
    ) {}

    public function handle(Customer $customer): PricedCart
    {
        $lines = $this->cart->linesOf($customer);
        $kept = $lines->filter(fn (CartItem $item): bool => $item->kind === CartLineKind::Preorder
            ? $this->orderable->preorderable($item->skuUnit)
            : $item->skuUnit->sku->product->status === ProductStatus::Active && $item->skuUnit->isActive());
        $gone = $lines->diff($kept);

        $removed = [];

        foreach ($gone as $item) {
            $removed[] = ['product_name' => $item->skuUnit->sku->product->name, 'reason' => $item->kind === CartLineKind::Preorder ? 'preorder_closed' : 'inactive'];
        }

        if ($gone->isNotEmpty()) {
            CartItem::query()->whereKey($gone->modelKeys())->delete();
        }

        return $this->pricer->price(
            $kept->filter(fn (CartItem $item): bool => $item->kind === CartLineKind::Order)->values(),
            $removed,
            $kept->filter(fn (CartItem $item): bool => $item->kind === CartLineKind::Preorder)->values(),
            $this->pricing->for($customer, array_values($kept->map(fn (CartItem $item): int => $item->sku_id)->all())),
        );
    }
}
