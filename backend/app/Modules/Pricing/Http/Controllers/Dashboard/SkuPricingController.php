<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Http\Requests\UpdateSkuPricingRequest;
use App\Modules\Pricing\Http\Resources\SkuPricingResource;
use App\Modules\Pricing\Queries\SkuPricingQuery;

final readonly class SkuPricingController
{
    /** PUT /api/dashboard/products/{product}/units/{skuUnit}/pricing — BR-PR01..05, BR-PR10, BR-MOQ01, per unit (D91) */
    public function update(UpdateSkuPricingRequest $request, Product $product, SkuUnit $skuUnit, SkuPricingQuery $units, UpdateSkuPricing $update): SkuPricingResource
    {
        return new SkuPricingResource($update->handle($units->forUnit($product, $skuUnit), SkuPricingData::fromRequest($request)));
    }
}
