<?php

declare(strict_types=1);

namespace App\Modules\Collections\Models\Scopes;

use App\Modules\Collections\Models\CashCollection;
use Illuminate\Database\Eloquent\Builder;

/** BR-C03: the collections that count towards an order's collected amount: `->tap(new NotVoided)`. */
final readonly class NotVoided
{
    /**
     * @param  Builder<CashCollection>  $query
     * @return Builder<CashCollection>
     */
    public function __invoke(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
