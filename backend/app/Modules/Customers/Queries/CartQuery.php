<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;
use Illuminate\Database\Eloquent\Collection;

/** BR-CU06: a customer's cart lines, oldest first, with each SKU's product, unit, tiers and stock for pricing. */
final readonly class CartQuery
{
    /**
     * @return Collection<int, CartItem>
     */
    public function linesOf(Customer $customer): Collection
    {
        $relations = array_map(fn (string $relation): string => 'sku.'.$relation, CartSkuQuery::RELATIONS);

        return CartItem::query()
            ->tap(new InCartOf($customer))
            ->with($relations)
            ->orderBy('id')
            ->get();
    }
}
