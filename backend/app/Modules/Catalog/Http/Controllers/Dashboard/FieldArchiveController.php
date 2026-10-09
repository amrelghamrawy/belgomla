<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\ArchiveFieldDefinition;
use App\Modules\Catalog\Http\Resources\FieldDefinitionResource;
use App\Modules\Catalog\Models\ProductFieldDefinition;

final readonly class FieldArchiveController
{
    /** POST /api/dashboard/fields/{field}/archive */
    public function store(ProductFieldDefinition $field, ArchiveFieldDefinition $archive): FieldDefinitionResource
    {
        return new FieldDefinitionResource($archive->handle($field));
    }
}
