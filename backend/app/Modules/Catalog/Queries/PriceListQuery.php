<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-P13 printable price list: every visible product of the selection, by name, unpaginated but capped
 * at `catalog.price_list_limit` products (each shows one row per unit it is sold in, D91) (one extra row is read to know whether the list was cut).
 */
final readonly class PriceListQuery
{
    public function __construct(private FilteredProductsQuery $filtered) {}

    /**
     * @return array{rows: Collection<int, Product>, truncated: bool}
     */
    public function get(ProductFilterData $filter): array
    {
        $limit = config()->integer('catalog.price_list_limit');

        $rows = $this->builder($filter)->limit($limit + 1)->get();

        return ['rows' => $rows->take($limit)->values(), 'truncated' => $rows->count() > $limit];
    }

    /**
     * The whole selection, uncapped and sorted, with what a row shows (PriceListExportQuery reads it lazily).
     *
     * @return Builder<Product>
     */
    public function builder(ProductFilterData $filter): Builder
    {
        return $this->filtered->builder($filter)
            ->tap(new VisibleOnStore)
            ->with(['category', 'sku.activeUnits.unit', 'sku.activeUnits.tiers']) // tiers: a group price shows only when below them (BR-PR14)
            ->orderBy('products.name')
            ->orderBy('products.id');
    }
}
