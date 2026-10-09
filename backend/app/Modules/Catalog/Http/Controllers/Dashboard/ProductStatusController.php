<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\SetProductStatus;
use App\Modules\Catalog\Http\Requests\SetProductStatusRequest;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\ProductDetailQuery;

final readonly class ProductStatusController
{
    /** PATCH /api/dashboard/products/{product}/status */
    public function update(SetProductStatusRequest $request, Product $product, SetProductStatus $setStatus, ProductDetailQuery $detail): DashboardProductResource
    {
        return new DashboardProductResource($detail->load($setStatus->handle($product, $request->status())));
    }
}
