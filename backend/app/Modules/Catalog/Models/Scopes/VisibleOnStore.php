<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models\Scopes;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Products a customer may see: active and coming_soon, never inactive (BR-P04).
 */
final class VisibleOnStore
{
    /**
     * @param  Builder<Product>  $query
     */
    public function __invoke(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), ProductStatus::visibleOnStore());
    }
}
