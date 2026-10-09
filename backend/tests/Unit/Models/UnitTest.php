<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $unit = $this->inTenant(fn () => Unit::factory()->create()->fresh());

    expect(array_keys($unit->toArray()))->toBe(['id', 'tenant_id', 'name', 'status', 'created_at', 'updated_at']);
});

test('defaults to active and casts the status', function (): void {
    $unit = $this->inTenant(fn () => Unit::query()->create(['name' => 'كرتونة'])->fresh());

    expect($unit->status)->toBe(ArchiveStatus::Active);
});

test('BR-U01 a unit name is unique per tenant', function (): void {
    $this->inTenant(fn () => Unit::factory()->create(['name' => 'كرتونة']));

    $this->inTenant(fn () => Unit::factory()->create(['name' => 'كرتونة']));
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

test('BR-U01 the same unit name is allowed in another tenant', function (): void {
    $this->inTenant(fn () => Unit::factory()->create(['name' => 'كرتونة']));
    $this->inTenant(fn () => Unit::factory()->create(['name' => 'كرتونة']), $this->otherTenant());

    expect(Unit::withoutTenantScope()->where('name', 'كرتونة')->count())->toBe(2);
});
