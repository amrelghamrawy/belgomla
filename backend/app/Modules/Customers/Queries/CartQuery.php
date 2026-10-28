<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU06: a customer's cart lines, oldest first, with each line's SKU unit, its SKU's product and stock and the unit's
 * tiers for pricing; all of them, or only the order or the pre-order lines (BR-O14).
 */
final readonly class CartQuery
{
    /**
     * @return Collection<int, CartItem>
     */
    public function linesOf(Customer $customer, ?CartLineKind $kind = null): Collection
    {
        $relations = array_map(fn (string $relation): string => 'skuUnit.'.$relation, CartSkuQuery::RELATIONS);

        $lines = CartItem::query()->tap(new InCartOf($customer))->with($relations);

        if ($kind instanceof CartLineKind) {
            $lines->where('kind', $kind);
        }

        return $lines->orderBy('id')->get();
    }

    /**
     * D91: the base units the cart's order lines already take of the unit's SKU in its other units (piece and carton
     * draw on the same stock, BR-U03). Pre-order lines hold no stock (BR-O14), so they count nothing.
     */
    public function heldBesides(Customer $customer, SkuUnit $unit, CartLineKind $kind = CartLineKind::Order): int
    {
        if ($kind === CartLineKind::Preorder) {
            return 0;
        }

        return (int) CartItem::query()
            ->tap(new InCartOf($customer))
            ->join('sku_units', 'sku_units.id', '=', 'cart_items.sku_unit_id')
            ->where('cart_items.sku_id', $unit->sku_id)
            ->where('cart_items.sku_unit_id', '!=', $unit->id)
            ->where('cart_items.kind', CartLineKind::Order)
            ->sum(DB::raw('cart_items.quantity * sku_units.conversion_factor'));
    }
}
