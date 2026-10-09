<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\CreateUnit;
use App\Modules\Catalog\Actions\UpdateUnit;
use App\Modules\Catalog\Http\Requests\SaveUnitRequest;
use App\Modules\Catalog\Http\Resources\UnitResource;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Queries\UnitsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class UnitController
{
    /** GET /api/dashboard/units */
    public function index(UnitsQuery $units): AnonymousResourceCollection
    {
        return UnitResource::collection($units->get());
    }

    /** POST /api/dashboard/units */
    public function store(SaveUnitRequest $request, CreateUnit $create): JsonResponse
    {
        return new UnitResource($create->handle($request->name()))->response()->setStatusCode(201);
    }

    /** PUT /api/dashboard/units/{unit} */
    public function update(SaveUnitRequest $request, Unit $unit, UpdateUnit $update): UnitResource
    {
        return new UnitResource($update->handle($unit, $request->name()));
    }
}
