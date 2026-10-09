<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Actions\RecomputeFromPercentages;
use App\Modules\Pricing\Http\Resources\SkuPricingResource;
use App\Modules\Pricing\Queries\SkuPricingQuery;

final readonly class PricingRecomputeController
{
    /** POST /api/dashboard/products/{product}/pricing/recompute — BR-PR10 */
    public function store(Product $product, SkuPricingQuery $sku, RecomputeFromPercentages $recompute): SkuPricingResource
    {
        return new SkuPricingResource($recompute->handle($sku->forProduct($product)));
    }
}
