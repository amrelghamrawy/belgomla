<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Actions\VoidCollection;
use App\Modules\Collections\Http\Requests\VoidCollectionRequest;
use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Collections\Models\CashCollection;

final readonly class CollectionVoidController
{
    /** POST /api/dashboard/collections/{id}/void — BR-C02, BR-C03 */
    public function store(VoidCollectionRequest $request, CashCollection $collection, VoidCollection $void): CollectionResource
    {
        return new CollectionResource($void->handle($collection, $request->reason(), $request->actorId()));
    }
}
