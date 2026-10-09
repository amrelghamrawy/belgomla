<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\AddProductImage;
use App\Modules\Catalog\Actions\RemoveProductImage;
use App\Modules\Catalog\Http\Requests\UploadProductImageRequest;
use App\Modules\Catalog\Http\Resources\ProductImageResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class ProductImageController
{
    /** POST /api/dashboard/products/{product}/images (multipart: file, is_main?) */
    public function store(UploadProductImageRequest $request, Product $product, AddProductImage $add): JsonResponse
    {
        $image = $add->handle($product, $request->image(), $request->isMain());

        return new ProductImageResource($image)->response()->setStatusCode(201);
    }

    /** DELETE /api/dashboard/products/{product}/images/{image} (scoped: the image must belong to the product) */
    public function destroy(Product $product, ProductImage $image, RemoveProductImage $remove): JsonResponse
    {
        $remove->handle($image);

        return ApiResponse::noContent();
    }
}
