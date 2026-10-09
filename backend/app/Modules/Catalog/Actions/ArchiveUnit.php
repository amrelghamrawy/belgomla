<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Exceptions\UnitInUse;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/** Rejected with 409 in_use while any SKU sells or stocks in the unit (08-api-contract.md §6). */
final readonly class ArchiveUnit
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Unit $unit): Unit
    {
        $skus = Sku::query()
            ->where(fn (Builder $query): Builder => $query->where('selling_unit_id', $unit->id)->orWhere('base_unit_id', $unit->id))
            ->count();

        if ($skus > 0) {
            throw UnitInUse::bySkus($skus);
        }

        if ($unit->status === ArchiveStatus::Archived) {
            return $unit;
        }

        $unit->update(['status' => ArchiveStatus::Archived]);

        $this->audit->log('unit.archived', $unit, ['status' => ArchiveStatus::Active->value], ['status' => ArchiveStatus::Archived->value]);

        return $unit;
    }
}
