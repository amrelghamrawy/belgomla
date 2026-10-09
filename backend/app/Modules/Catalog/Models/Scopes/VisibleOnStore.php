<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models\Scopes;

use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Products a customer may see: active and coming_soon, never inactive (BR-P04). Applies to any query
 * on products, including relation queries (related products, whereHas).
 */
final class VisibleOnStore
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function __invoke(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), ProductStatus::visibleOnStore());
    }
}
