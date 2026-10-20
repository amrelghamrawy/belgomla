<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;

/**
 * BR-CU06: empties the customer's cart, or only its order or pre-order lines (checkout and the pre-order confirmation
 * each take their own, BR-O14). The cart row stays (one per customer).
 */
final readonly class ClearCart
{
    public function handle(Customer $customer, ?CartLineKind $kind = null): void
    {
        $lines = CartItem::query()->tap(new InCartOf($customer));

        if ($kind instanceof CartLineKind) {
            $lines->where('kind', $kind);
        }

        $lines->delete();
    }
}
