<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Http\Requests\UpdateSkuPricingRequest;
use App\Modules\Pricing\Http\Resources\SkuPricingResource;
use App\Modules\Pricing\Queries\SkuPricingQuery;

final readonly class SkuPricingController
{
    /** PUT /api/dashboard/products/{product}/pricing — BR-PR01..05, BR-PR10, BR-MOQ01 */
    public function update(UpdateSkuPricingRequest $request, Product $product, SkuPricingQuery $sku, UpdateSkuPricing $update): SkuPricingResource
    {
        return new SkuPricingResource($update->handle($sku->forProduct($product), SkuPricingData::fromRequest($request)));
    }
}
