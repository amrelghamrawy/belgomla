<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ArchiveUnit;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Exceptions\UnitInUse;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('ArchiveUnit archives an unused unit once', function (): void {
    $unit = Unit::factory()->create();

    app(ArchiveUnit::class)->handle($unit);
    app(ArchiveUnit::class)->handle($unit);

    expect($unit->refresh()->status)->toBe(ArchiveStatus::Archived)
        ->and(AuditLog::query()->where('action', 'unit.archived')->count())->toBe(1);
});

test('ArchiveUnit refuses a unit used by skus and reports how many', function (): void {
    $unit = Unit::factory()->create();
    Sku::factory()->count(2)->create(['selling_unit_id' => $unit->id]);

    try {
        app(ArchiveUnit::class)->handle($unit);
        $this->fail('Expected UnitInUse');
    } catch (UnitInUse $e) {
        expect($e->status)->toBe(409)
            ->and($e->errorCode)->toBe('in_use')
            ->and($e->details)->toBe(['skus' => 2]);
    }

    expect($unit->refresh()->status)->toBe(ArchiveStatus::Active);
});

test('ArchiveUnit ignores skus of another tenant', function (): void {
    $unit = Unit::factory()->create();
    $this->inTenant(fn () => Sku::factory()->create(), $this->otherTenant());

    expect(app(ArchiveUnit::class)->handle($unit)->status)->toBe(ArchiveStatus::Archived);
});
