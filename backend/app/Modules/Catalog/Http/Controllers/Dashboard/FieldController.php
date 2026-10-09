<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\CreateFieldDefinition;
use App\Modules\Catalog\Actions\UpdateFieldDefinition;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Http\Requests\StoreFieldRequest;
use App\Modules\Catalog\Http\Requests\UpdateFieldRequest;
use App\Modules\Catalog\Http\Resources\FieldDefinitionResource;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Queries\FieldDefinitionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class FieldController
{
    /** GET /api/dashboard/fields */
    public function index(FieldDefinitionsQuery $fields): AnonymousResourceCollection
    {
        return FieldDefinitionResource::collection($fields->get());
    }

    /** POST /api/dashboard/fields */
    public function store(StoreFieldRequest $request, CreateFieldDefinition $create): JsonResponse
    {
        return new FieldDefinitionResource($create->handle(FieldDefinitionData::fromRequest($request)))
            ->response()
            ->setStatusCode(201);
    }

    /** PUT /api/dashboard/fields/{field} */
    public function update(UpdateFieldRequest $request, ProductFieldDefinition $field, UpdateFieldDefinition $update): FieldDefinitionResource
    {
        return new FieldDefinitionResource($update->handle($field, FieldDefinitionData::fromRequest($request)));
    }
}
