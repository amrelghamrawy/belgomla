<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;

/**
 * The SKU of a product (V1: exactly one, BR-P02) with its tiers, for the dashboard pricing endpoints.
 * The product comes from route binding, already scoped to the tenant (BR-T04).
 */
final readonly class SkuPricingQuery
{
    public function forProduct(Product $product): Sku
    {
        return $product->sku()->with('tiers')->firstOrFail()->setRelation('product', $product);
    }
}
