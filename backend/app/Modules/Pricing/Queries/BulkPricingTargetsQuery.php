<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-PR11: the SKUs a bulk pricing touches, i.e. the SKU of every product matching the shared product
 * filter (the selection BulkSetProductStatus uses), with tiers and product loaded, in product order.
 */
final readonly class BulkPricingTargetsQuery
{
    public function __construct(private FilteredProductsQuery $products) {}

    /**
     * @return Collection<int, Sku>
     */
    public function get(ProductFilterData $filter): Collection
    {
        $skus = new Collection;

        foreach ($this->products->builder($filter)->with('sku.tiers')->orderBy('products.id')->get() as $product) {
            if ($product->sku instanceof Sku) {
                $skus->push($product->sku->setRelation('product', $product));
            }
        }

        return $skus;
    }
}
