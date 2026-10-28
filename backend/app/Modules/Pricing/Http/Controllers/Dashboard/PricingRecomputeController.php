<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Actions\RecomputeFromPercentages;
use App\Modules\Pricing\Http\Resources\SkuPricingResource;
use App\Modules\Pricing\Queries\SkuPricingQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class PricingRecomputeController
{
    /** POST /api/dashboard/products/{product}/pricing/recompute — BR-PR10, every unit of the product (D91) */
    public function store(Product $product, SkuPricingQuery $units, RecomputeFromPercentages $recompute): AnonymousResourceCollection
    {
        return SkuPricingResource::collection($recompute->handle($units->forProduct($product)));
    }
}
