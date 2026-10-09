<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Actions\UpdateProduct;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\ListDashboardProductsRequest;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\DashboardProductListQuery;
use App\Modules\Catalog\Queries\ProductDetailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ProductController
{
    /** GET /api/dashboard/products?q=&category=&status=&f[key]=&low_stock=&sort=&page=&per_page= */
    public function index(ListDashboardProductsRequest $request, DashboardProductListQuery $products): AnonymousResourceCollection
    {
        return DashboardProductResource::collection(
            $products->paginate(ProductFilterData::fromRequest($request), $request->sort(), $request->perPage(), $request->lowStock()),
        );
    }

    /** POST /api/dashboard/products */
    public function store(StoreProductRequest $request, CreateProduct $create, ProductDetailQuery $detail): JsonResponse
    {
        $product = $create->handle(ProductData::fromRequest($request), $request->actorId());

        return new DashboardProductResource($detail->load($product))->response()->setStatusCode(201);
    }

    /** GET /api/dashboard/products/{product} */
    public function show(Product $product, ProductDetailQuery $detail): DashboardProductResource
    {
        return new DashboardProductResource($detail->load($product));
    }

    /** PUT /api/dashboard/products/{product} */
    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $update, ProductDetailQuery $detail): DashboardProductResource
    {
        return new DashboardProductResource($detail->load($update->handle($product, ProductData::fromRequest($request))));
    }
}
