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
use App\Modules\Pricing\Contracts\CustomerPricing;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ProductController
{
    /** GET /api/store/products?category=&f[key]=&q=&on_offer=&sort=&page=&per_page= — prices are the shopper's own (BR-PR14, BR-PR15). */
    public function index(
        StoreProductListRequest $request,
        StoreProductListQuery $products,
        StorefrontFieldsQuery $fields,
        PriceVisibilityQuery $prices,
        CustomerPricing $pricing,
    ): AnonymousResourceCollection {
        $page = $products->paginate(ProductFilterData::fromRequest($request), $request->sort(), $request->perPage(), $request->onOffer());

        return StoreProductResource::page(
            $page,
            $fields->get(),
            $prices->hiddenFor($request->customer()),
            $pricing->for($request->customer(), StoreProductResource::skuIds($page->items())),
        );
    }

    /** GET /api/store/products/{slug} */
    public function show(
        ShowStoreProductRequest $request,
        string $slug,
        StoreProductDetailQuery $product,
        StorefrontFieldsQuery $fields,
        PriceVisibilityQuery $prices,
        CustomerPricing $pricing,
    ): StoreProductResource {
        $found = $product->bySlug($slug);

        return new StoreProductResource($found, $fields->get(), $prices->hiddenFor($request->customer()), $pricing->for($request->customer(), StoreProductResource::skuIds([$found])));
    }
}
