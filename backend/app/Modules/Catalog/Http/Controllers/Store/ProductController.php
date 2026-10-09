<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\ShowStoreProductRequest;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;
use App\Modules\Catalog\Http\Resources\StoreProductResource;
use App\Modules\Catalog\Queries\PriceVisibilityQuery;
use App\Modules\Catalog\Queries\StorefrontFieldsQuery;
use App\Modules\Catalog\Queries\StoreProductDetailQuery;
use App\Modules\Catalog\Queries\StoreProductListQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ProductController
{
    /** GET /api/store/products?category=&f[key]=&q=&sort=&page=&per_page= */
    public function index(
        StoreProductListRequest $request,
        StoreProductListQuery $products,
        StorefrontFieldsQuery $fields,
        PriceVisibilityQuery $prices,
    ): AnonymousResourceCollection {
        return StoreProductResource::page(
            $products->paginate(ProductFilterData::fromRequest($request), $request->sort(), $request->perPage()),
            $fields->get(),
            $prices->hiddenFor($request->customer()),
        );
    }

    /** GET /api/store/products/{slug} */
    public function show(
        ShowStoreProductRequest $request,
        string $slug,
        StoreProductDetailQuery $product,
        StorefrontFieldsQuery $fields,
        PriceVisibilityQuery $prices,
    ): StoreProductResource {
        return new StoreProductResource($product->bySlug($slug), $fields->get(), $prices->hiddenFor($request->customer()));
    }
}
