<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-CU06: a customer's cart lines, oldest first, with each SKU's product, unit, tiers and stock for pricing; all of
 * them, or only the order or the pre-order lines (BR-O14).
 */
final readonly class CartQuery
{
    /**
     * @return Collection<int, CartItem>
     */
    public function linesOf(Customer $customer, ?CartLineKind $kind = null): Collection
    {
        $relations = array_map(fn (string $relation): string => 'sku.'.$relation, CartSkuQuery::RELATIONS);

        $lines = CartItem::query()->tap(new InCartOf($customer))->with($relations);

        if ($kind instanceof CartLineKind) {
            $lines->where('kind', $kind);
        }

        return $lines->orderBy('id')->get();
    }
}
