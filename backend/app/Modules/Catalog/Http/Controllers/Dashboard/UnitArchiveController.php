<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\ArchiveUnit;
use App\Modules\Catalog\Http\Resources\UnitResource;
use App\Modules\Catalog\Models\Unit;

final readonly class UnitArchiveController
{
    /** POST /api/dashboard/units/{unit}/archive */
    public function store(Unit $unit, ArchiveUnit $archive): UnitResource
    {
        return new UnitResource($archive->handle($unit));
    }
}
