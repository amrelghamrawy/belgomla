<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\ArchiveFieldOption;
use App\Modules\Catalog\Http\Resources\FieldOptionResource;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;

final readonly class FieldOptionArchiveController
{
    /** POST /api/dashboard/fields/{field}/options/{option}/archive */
    public function store(ProductFieldDefinition $field, ProductFieldOption $option, ArchiveFieldOption $archive): FieldOptionResource
    {
        return new FieldOptionResource($archive->handle($option));
    }
}
