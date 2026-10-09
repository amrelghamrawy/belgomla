<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;

test('to array', function (): void {
    $log = $this->asTenant($this->tenant, fn (): AuditLog => AuditLog::create([
        'actor_type' => 'system', 'action' => 'test.action', 'entity_type' => 'tenants', 'entity_id' => 1,
    ]))->fresh();

    expect(array_keys($log->toArray()))->toBe([
        'id', 'tenant_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'batch_id', 'ip', 'user_agent', 'created_at',
    ]);
});

test('audit logs have no updated_at and cast json columns to arrays', function (): void {
    $log = $this->asTenant($this->tenant, fn (): AuditLog => AuditLog::create([
        'actor_type' => 'system', 'action' => 'a', 'entity_type' => 'b', 'old_values' => ['x' => 1], 'new_values' => ['x' => 2],
    ]))->fresh();

    expect($log->old_values)->toBe(['x' => 1])
        ->and($log->new_values)->toBe(['x' => 2])
        ->and($log->created_at)->not->toBeNull()
        ->and(AuditLog::UPDATED_AT)->toBeNull();
});

test('the api array groups actor and entity', function (): void {
    $log = $this->asTenant($this->tenant, fn (): AuditLog => AuditLog::create([
        'actor_type' => 'tenant_user', 'actor_id' => 7, 'action' => 'a', 'entity_type' => 'b', 'entity_id' => 9,
    ]));

    expect($log->toApiArray())->toMatchArray([
        'actor' => ['type' => 'tenant_user', 'id' => 7],
        'entity' => ['type' => 'b', 'id' => 9],
    ]);
});
