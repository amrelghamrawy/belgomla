<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;

test('to array', function (): void {
    $log = $this->asTenant($this->tenant, fn (): AuditLog => AuditLog::query()->create([
        'actor_type' => ActorType::System, 'action' => 'test.action', 'entity_type' => 'tenants', 'entity_id' => 1,
    ]))->fresh();

    expect(array_keys($log->toArray()))->toBe([
        'id', 'tenant_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'batch_id', 'ip', 'user_agent', 'created_at',
    ]);
});

test('casts: no updated_at, json columns to arrays, actor type to enum', function (): void {
    $log = $this->asTenant($this->tenant, fn (): AuditLog => AuditLog::query()->create([
        'actor_type' => ActorType::TenantUser, 'action' => 'a', 'entity_type' => 'b', 'old_values' => ['x' => 1], 'new_values' => ['x' => 2],
    ]))->fresh();

    expect($log->old_values)->toBe(['x' => 1])
        ->and($log->new_values)->toBe(['x' => 2])
        ->and($log->actor_type)->toBe(ActorType::TenantUser)
        ->and($log->created_at)->not->toBeNull()
        ->and(AuditLog::UPDATED_AT)->toBeNull();
});
