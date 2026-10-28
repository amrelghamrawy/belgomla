<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Services\ActivePromotions;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Storefront list (08-api-contract.md §1): active and coming_soon products only (BR-P04), the shared
 * filter, sort `name | -created_at | price | -price` (default newest first), paginated. `$onOffer` keeps the products whose
 * SKU is on a live promotion (BR-PR15, V2 Task 12), read through Pricing's ActivePromotions.
 */
final readonly class StoreProductListQuery
{
    public const array RELATIONS = ['category', 'activeSkus.activeUnits.unit', 'activeSkus.activeUnits.tiers', 'activeSkus.inventory', 'activeSkus.optionValues', 'options.values.image', 'mainImage'];

    public function __construct(private FilteredProductsQuery $filtered, private ActivePromotions $promotions) {}

    /**
     * @param  array{0: string, 1: 'asc'|'desc'}|null  $sort
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(ProductFilterData $filter, ?array $sort, int $perPage, bool $onOffer = false): LengthAwarePaginator
    {
        $query = $this->filtered->builder($filter)->tap(new VisibleOnStore)->with(self::RELATIONS);

        if ($onOffer) {
            $query->whereIn('products.id', Sku::query()->where('skus.status', 'active')->whereIn('skus.id', $this->promotions->skuIdsQuery())->select('skus.product_id'));
        }

        DashboardProductListQuery::applySort($query, $sort ?? ['created_at', 'desc']);

        return $query->paginate($perPage);
    }
}
