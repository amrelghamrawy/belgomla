<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\SetProductStatus;
use App\Modules\Catalog\Contracts\PreorderDemand;
use App\Modules\Catalog\Http\Requests\SetProductStatusRequest;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\ProductDetailQuery;

final readonly class ProductStatusController
{
    /**
     * PATCH /api/dashboard/products/{product}/status — with `meta.preorder_demand`, so a Coming Soon product that goes
     * active shows what customers pre-ordered; converting them stays the merchant's own step (BR-O14, D88).
     */
    public function update(SetProductStatusRequest $request, Product $product, SetProductStatus $setStatus, ProductDetailQuery $detail, PreorderDemand $demand): DashboardProductResource
    {
        $product = $setStatus->handle($product, $request->status());

        return new DashboardProductResource($detail->load($product))->additional(['meta' => ['preorder_demand' => $demand->of($product)]]);
    }
}
