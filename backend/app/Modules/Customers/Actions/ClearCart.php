<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;

/** BR-CU06: empties the customer's cart. The cart row stays (one per customer). */
final readonly class ClearCart
{
    public function handle(Customer $customer): void
    {
        CartItem::query()
            ->tap(new InCartOf($customer))
            ->delete();
    }
}
