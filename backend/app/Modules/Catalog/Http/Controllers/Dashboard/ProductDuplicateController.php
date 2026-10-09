<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\DuplicateProduct;
use App\Modules\Catalog\Http\Requests\DuplicateProductRequest;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\ProductDetailQuery;
use Illuminate\Http\JsonResponse;

final readonly class ProductDuplicateController
{
    /** POST /api/dashboard/products/{product}/duplicate — BR-P11, returns the new inactive product. */
    public function store(DuplicateProductRequest $request, Product $product, DuplicateProduct $duplicate, ProductDetailQuery $detail): JsonResponse
    {
        $copy = $duplicate->handle($product, $request->actorId());

        return new DashboardProductResource($detail->load($copy))->response()->setStatusCode(201);
    }
}
