<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\CreateBundle;
use App\Modules\Catalog\Actions\UpdateBundle;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Http\Requests\StoreBundleRequest;
use App\Modules\Catalog\Http\Requests\UpdateBundleRequest;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\ProductDetailQuery;
use Illuminate\Http\JsonResponse;

/** Bundles (BR-P15, V2 Task 15, D93): a bundle is a product; it is read through the products endpoints. */
final readonly class BundleController
{
    /** POST /api/dashboard/bundles */
    public function store(StoreBundleRequest $request, CreateBundle $create, ProductDetailQuery $detail): JsonResponse
    {
        $product = $create->handle(BundleData::fromRequest($request), $request->actorId());

        return new DashboardProductResource($detail->load($product))->response()->setStatusCode(201);
    }

    /** PUT /api/dashboard/bundles/{product} */
    public function update(UpdateBundleRequest $request, Product $product, UpdateBundle $update, ProductDetailQuery $detail): DashboardProductResource
    {
        return new DashboardProductResource($detail->load($update->handle($product, BundleData::fromRequest($request))));
    }
}
