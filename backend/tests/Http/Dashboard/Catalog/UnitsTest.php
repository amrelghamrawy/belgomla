<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('owners list the units of their tenant only, active first', function (): void {
    [$box, $archived] = $this->inTenant(fn (): array => [
        Unit::factory()->create(['name' => 'علبة']),
        Unit::factory()->archived()->create(['name' => 'أ-قديمة']),
    ]);
    $this->inTenant(fn () => Unit::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/units')
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'name', 'status']]])
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $box->id)
        ->assertJsonPath('data.1.id', $archived->id);
});

test('BR-U01 an owner creates a unit, audited', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/units', ['name' => ' كرتونة '])
        ->assertCreated()
        ->assertExactJson(['data' => ['id' => Unit::withoutTenantScope()->max('id'), 'name' => 'كرتونة', 'status' => 'active']])
        ->json('data.id');

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'unit.created', 'entity_id' => $id, 'actor_id' => $this->me->id]);
});

test('unit validation', function (array $body): void {
    $this->tenantJson('POST', '/api/dashboard/units', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['name']]]);
})->with([
    'missing name' => [[]],
    'empty name' => [['name' => '']],
    'name too long' => [['name' => str_repeat('a', 51)]],
]);

test('BR-U01 a unit name is unique per tenant', function (): void {
    $this->inTenant(fn () => Unit::factory()->create(['name' => 'كرتونة']));

    $this->tenantJson('POST', '/api/dashboard/units', ['name' => 'كرتونة'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_name');
});

test('BR-U01 the same unit name is allowed when another tenant has it', function (): void {
    $this->inTenant(fn () => Unit::factory()->create(['name' => 'كرتونة']), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/units', ['name' => 'كرتونة'])->assertCreated();
});

test('an owner renames a unit, audited', function (): void {
    $unit = $this->inTenant(fn () => Unit::factory()->create(['name' => 'علبه']));

    $this->tenantJson('PUT', "/api/dashboard/units/{$unit->id}", ['name' => 'علبة'])
        ->assertOk()
        ->assertJsonPath('data.name', 'علبة');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'unit.updated', 'entity_id' => $unit->id,
        'old_values' => json_encode(['name' => 'علبه']), 'new_values' => json_encode(['name' => 'علبة']),
    ]);
});

test('renaming a unit to an existing name conflicts', function (): void {
    $unit = $this->inTenant(function (): Unit {
        Unit::factory()->create(['name' => 'كرتونة']);

        return Unit::factory()->create(['name' => 'علبة']);
    });

    $this->tenantJson('PUT', "/api/dashboard/units/{$unit->id}", ['name' => 'كرتونة'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_name');
});

test('an unused unit is archived, audited', function (): void {
    $unit = $this->inTenant(fn () => Unit::factory()->create());

    $this->tenantJson('POST', "/api/dashboard/units/{$unit->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    $this->assertDatabaseHas('audit_logs', ['action' => 'unit.archived', 'entity_id' => $unit->id]);
});

test('a unit used by a sku cannot be archived', function (string $column): void {
    $unit = $this->inTenant(function () use ($column): Unit {
        $unit = Unit::factory()->create();
        Sku::factory()->create([$column => $unit->id]);

        return $unit;
    });

    $this->tenantJson('POST', "/api/dashboard/units/{$unit->id}/archive")
        ->assertConflict()
        ->assertJsonPath('error.code', 'in_use')
        ->assertJsonPath('error.details.skus', 1);

    $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'active']);
})->with(['as selling unit' => 'selling_unit_id', 'as base unit' => 'base_unit_id']);

test('BR-T04 a unit of another tenant is 404 and untouched', function (string $method, string $suffix): void {
    $foreign = $this->inTenant(fn () => Unit::factory()->create(['name' => 'original']), $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/units/{$foreign->id}{$suffix}", ['name' => 'hacked'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('units', ['id' => $foreign->id, 'name' => 'original', 'status' => 'active']);
})->with([
    'rename' => ['PUT', ''],
    'archive' => ['POST', '/archive'],
]);
