<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Data\CollectionFiltersData;
use App\Modules\Collections\Http\Requests\ListCollectionsRequest;
use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Collections\Queries\CollectionsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CollectionController
{
    /** GET /api/dashboard/collections?from=&to=&customer_id=&order= — BR-C02 */
    public function index(ListCollectionsRequest $request, CollectionsQuery $collections): AnonymousResourceCollection
    {
        return CollectionResource::collection($collections->paginate(CollectionFiltersData::fromRequest($request), $request->perPage()));
    }
}
