<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateUnit;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-U01 CreateUnit creates an active unit, audited', function (): void {
    $unit = app(CreateUnit::class)->handle('كرتونة');

    expect($unit->status)->toBe(ArchiveStatus::Active)
        ->and(AuditLog::query()->sole()->action)->toBe('unit.created');
});

test('BR-U01 CreateUnit refuses a duplicate name and writes no audit', function (): void {
    Unit::factory()->create(['name' => 'كرتونة']);

    expect(fn () => app(CreateUnit::class)->handle('كرتونة'))->toThrow(CatalogConflict::class, 'توجد وحدة بنفس الاسم');

    expect(AuditLog::query()->count())->toBe(0);
});
