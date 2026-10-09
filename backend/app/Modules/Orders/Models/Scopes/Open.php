<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models\Scopes;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/** Orders not delivered or cancelled yet, i.e. those holding a reservation (BR-INV03..05): `->tap(new Open)`. */
final readonly class Open
{
    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function __invoke(Builder $query): Builder
    {
        return $query->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Cancelled]);
    }
}
