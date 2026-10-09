<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\AddProductRelation;
use App\Modules\Catalog\Actions\RemoveProductRelation;
use App\Modules\Catalog\Http\Requests\StoreProductRelationRequest;
use App\Modules\Catalog\Http\Resources\ProductRelationResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Queries\ProductRelationsQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ProductRelationController
{
    /** GET /api/dashboard/products/{product}/relations — both directions (BR-P10). */
    public function index(Product $product, ProductRelationsQuery $relations): AnonymousResourceCollection
    {
        return ProductRelationResource::forProduct($relations->load($product));
    }

    /** POST /api/dashboard/products/{product}/relations */
    public function store(StoreProductRelationRequest $request, Product $product, AddProductRelation $add): JsonResponse
    {
        $relation = $add->handle($product, $request->relatedProductId(), $request->type());

        return new ProductRelationResource($relation, $product->id)->response()->setStatusCode(201);
    }

    /** DELETE /api/dashboard/products/{product}/relations/{relation} (scoped to the product's own relations) */
    public function destroy(Product $product, ProductRelation $relation, RemoveProductRelation $remove): JsonResponse
    {
        $remove->handle($relation);

        return ApiResponse::noContent();
    }
}
