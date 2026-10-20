<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models\Scopes;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/** BR-O12: order lines the merchant did not remove, the only ones in totals, stock and returns: `->tap(new Live)`. */
final readonly class Live
{
    /**
     * @param  Builder<OrderItem>  $query
     * @return Builder<OrderItem>
     */
    public function __invoke(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }
}
