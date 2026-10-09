<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->log = fn (array $attributes = [], $tenant = null): AuditLog => $this->inTenant(fn (): AuditLog => AuditLog::query()->create([
        'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id, 'action' => 'order.status_changed', 'entity_type' => 'orders', 'entity_id' => 1, ...$attributes,
    ]), $tenant);
});

test('BR-L the audit log lists the trail newest first in the audit shape', function (): void {
    $old = ($this->log)(['created_at' => now()->subHour(), 'old_values' => ['status' => 'pending'], 'new_values' => ['status' => 'confirmed']]);
    $new = ($this->log)();

    $this->tenantJson('GET', '/api/dashboard/audit')
        ->assertOk()
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.1.actor', ['type' => 'tenant_user', 'id' => $this->owner->id])
        ->assertJsonPath('data.1.entity', ['type' => 'orders', 'id' => 1])
        ->assertJsonPath('data.1.new_values', ['status' => 'confirmed'])
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'actor' => ['type', 'id'], 'action', 'entity' => ['type', 'id'], 'old_values', 'new_values', 'batch_id', 'ip', 'created_at']], 'links', 'meta']);
});

test('BR-L the trail of one entity and of one actor', function (): void {
    $collection = ($this->log)(['action' => 'collection.recorded', 'entity_type' => 'collections', 'entity_id' => 5, 'actor_id' => 42]);
    ($this->log)();

    $this->tenantJson('GET', '/api/dashboard/audit?entity_type=collections&entity_id=5')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $collection->id);
    $this->tenantJson('GET', '/api/dashboard/audit?actor_id=42')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $collection->id);
});

test('D60 the from/to filters are cairo business days', function (): void {
    ($this->log)(['created_at' => '2026-10-04 20:59:59']);
    $inside = ($this->log)(['created_at' => '2026-10-04 21:00:00']);

    $this->tenantJson('GET', '/api/dashboard/audit?from=2026-10-05&to=2026-10-05')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inside->id);
});

test('BR-L filtering by batch id returns exactly the rows of a bulk action', function (): void {
    $first = $this->orderableSku(['code' => 'A-1']);
    $second = $this->orderableSku(['code' => 'B-1']);
    ($this->log)();
    $batch = $this->tenantJson('POST', '/api/dashboard/inventory/bulk-adjust', [
        ['sku_id' => $first->id, 'new_on_hand' => 25, 'reason' => 'جرد'],
        ['sku_id' => $second->id, 'new_on_hand' => 30, 'reason' => 'جرد'],
    ])->assertOk()->json('data.batch_id');
    $rows = AuditLog::withoutTenantScope()->where('batch_id', $batch)->orderByDesc('id')->pluck('id')->all();

    $this->tenantJson('GET', "/api/dashboard/audit?batch_id={$batch}")
        ->assertOk()
        ->assertJsonPath('data.*.id', $rows)
        ->assertJsonPath('data.*.batch_id', array_fill(0, count($rows), $batch));

    expect(count($rows))->toBeGreaterThanOrEqual(2);
});

test('every audit filter is validated', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/audit?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'entity type too long' => ['entity_type='.str_repeat('a', 41), 'entity_type'],
    'entity id not a number' => ['entity_id=x', 'entity_id'],
    'actor id not a number' => ['actor_id=x', 'actor_id'],
    'from not a day' => ['from=yesterday', 'from'],
    'to before from' => ['from=2026-10-05&to=2026-10-04', 'to'],
    'batch id not a uuid' => ['batch_id=123', 'batch_id'],
]);

test('BR-T03 the audit log never shows another tenant', function (): void {
    ($this->log)([], $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/audit')->assertOk()->assertJsonCount(0, 'data');
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (): void {
    $this->tenantJson('GET', '/api/dashboard/audit', tenant: $this->otherTenant())->assertUnauthorized();
});
