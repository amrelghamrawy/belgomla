<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\Unit;

/** BR-U01: tenant-defined unit names, unique per tenant. */
final readonly class CreateUnit
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(string $name): Unit
    {
        if (Unit::query()->where('name', $name)->exists()) {
            throw CatalogConflict::duplicateUnitName();
        }

        $unit = Unit::query()->create(['name' => $name]);

        $this->audit->log('unit.created', $unit, null, ['name' => $name]);

        return $unit->refresh();
    }
}
