<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\UpdateUnit;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('UpdateUnit renames and allows keeping its own name', function (): void {
    $unit = Unit::factory()->create(['name' => 'علبة']);

    app(UpdateUnit::class)->handle($unit, 'علبة');
    app(UpdateUnit::class)->handle($unit, 'باكو');

    expect($unit->refresh()->name)->toBe('باكو');
});

test('UpdateUnit refuses the name of another unit', function (): void {
    Unit::factory()->create(['name' => 'كرتونة']);
    $unit = Unit::factory()->create(['name' => 'علبة']);

    app(UpdateUnit::class)->handle($unit, 'كرتونة');
})->throws(CatalogConflict::class);
