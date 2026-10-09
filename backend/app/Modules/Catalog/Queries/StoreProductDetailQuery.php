<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Storefront product page by slug (08-api-contract.md §1): the list shape plus the gallery and the
 * related products in both directions. Inactive products are 404; relations to inactive products are
 * hidden, not deleted (BR-P10).
 */
final readonly class StoreProductDetailQuery
{
    public function bySlug(string $slug): Product
    {
        $visible = fn (Builder $query): Builder => $query->tap(new VisibleOnStore);
        $visibleRelated = fn (Relation $query): Relation => $query->tap(new VisibleOnStore)->with(StoreProductListQuery::RELATIONS);

        return Product::query()
            ->tap(new VisibleOnStore)
            ->where('slug', $slug)
            ->with([
                ...StoreProductListQuery::RELATIONS,
                'images',
                'relations' => fn (Relation $query): Relation => $query->whereHas('relatedProduct', $visible)
                    ->with(['relatedProduct' => $visibleRelated]),
                'incomingRelations' => fn (Relation $query): Relation => $query->whereHas('product', $visible)
                    ->with(['product' => $visibleRelated]),
            ])
            ->firstOrFail();
    }
}
