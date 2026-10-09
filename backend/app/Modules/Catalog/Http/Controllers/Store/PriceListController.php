<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;
use App\Modules\Catalog\Http\Resources\PriceListRowResource;
use App\Modules\Catalog\Queries\PriceListQuery;
use App\Modules\Catalog\Queries\PriceVisibilityQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class PriceListController
{
    /** GET /api/store/price-list — BR-P13, same filters as the product list, unpaginated (capped). */
    public function index(StoreProductListRequest $request, PriceListQuery $priceList, PriceVisibilityQuery $prices): AnonymousResourceCollection
    {
        $list = $priceList->get(ProductFilterData::fromRequest($request));

        return PriceListRowResource::list($list['rows'], $prices->hiddenFor($request->customer()), $list['truncated']);
    }
}
