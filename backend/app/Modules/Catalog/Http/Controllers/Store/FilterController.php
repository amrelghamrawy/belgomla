<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;
use App\Modules\Catalog\Http\Resources\FacetResource;
use App\Modules\Catalog\Queries\FacetQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class FilterController
{
    /** GET /api/store/filters — facets with counts for the current selection (BR-P08). */
    public function index(StoreProductListRequest $request, FacetQuery $facets): AnonymousResourceCollection
    {
        return FacetResource::collection($facets->get(ProductFilterData::fromRequest($request)));
    }
}
