<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models\Scopes;

use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/** Cart lines of one customer's cart (BR-CU06): `CartItem::query()->tap(new InCartOf($customer))`. */
final readonly class InCartOf
{
    public function __construct(private Customer $customer) {}

    /**
     * @param  Builder<CartItem>  $query
     * @return Builder<CartItem>
     */
    public function __invoke(Builder $query): Builder
    {
        return $query->whereHas('cart', fn (Builder $cart): Builder => $cart->where('customer_id', $this->customer->id));
    }
}
