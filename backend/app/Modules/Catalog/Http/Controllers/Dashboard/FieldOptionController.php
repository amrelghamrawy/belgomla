<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\CreateFieldOption;
use App\Modules\Catalog\Actions\UpdateFieldOption;
use App\Modules\Catalog\Http\Requests\StoreFieldOptionRequest;
use App\Modules\Catalog\Http\Requests\UpdateFieldOptionRequest;
use App\Modules\Catalog\Http\Resources\FieldOptionResource;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use Illuminate\Http\JsonResponse;

final readonly class FieldOptionController
{
    /** POST /api/dashboard/fields/{field}/options */
    public function store(StoreFieldOptionRequest $request, ProductFieldDefinition $field, CreateFieldOption $create): JsonResponse
    {
        return new FieldOptionResource($create->handle($field, $request->label(), $request->sortOrder()))
            ->response()
            ->setStatusCode(201);
    }

    /** PUT /api/dashboard/fields/{field}/options/{option} (scoped binding: the option must belong to the field) */
    public function update(UpdateFieldOptionRequest $request, ProductFieldDefinition $field, ProductFieldOption $option, UpdateFieldOption $update): FieldOptionResource
    {
        return new FieldOptionResource($update->handle($option, $request->label(), $request->sortOrder()));
    }
}
