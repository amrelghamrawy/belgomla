<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\Unit;

/** Renames a unit; SKUs reference it by id, so the change shows everywhere at once. */
final readonly class UpdateUnit
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Unit $unit, string $name): Unit
    {
        if (Unit::query()->where('name', $name)->whereKeyNot($unit->id)->exists()) {
            throw CatalogConflict::duplicateUnitName();
        }

        $old = ['name' => $unit->name];
        $unit->update(['name' => $name]);

        $this->audit->log('unit.updated', $unit, $old, ['name' => $name]);

        return $unit;
    }
}
