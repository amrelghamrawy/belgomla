<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;
use App\Modules\Catalog\Http\Resources\PriceListRowResource;
use App\Modules\Catalog\Queries\PriceListQuery;
use App\Modules\Catalog\Queries\PriceVisibilityQuery;
use App\Modules\Pricing\Contracts\CustomerPricing;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class PriceListController
{
    /** GET /api/store/price-list — BR-P13, same filters as the product list, unpaginated (capped), at the shopper's own prices (BR-PR14, BR-PR15). */
    public function index(StoreProductListRequest $request, PriceListQuery $priceList, PriceVisibilityQuery $prices, CustomerPricing $pricing): AnonymousResourceCollection
    {
        $list = $priceList->get(ProductFilterData::fromRequest($request));

        return PriceListRowResource::list($list['rows'], $prices->hiddenFor($request->customer()), $list['truncated'], $pricing->for($request->customer(), PriceListRowResource::skuIds($list['rows'])));
    }
}
