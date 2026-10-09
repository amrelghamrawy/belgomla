<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\Request;

test('only the keys whose values changed are recorded on both sides', function (): void {
    $logger = new AuditLogger(Request::create('/'));

    $log = $this->asTenant($this->tenant, fn (): AuditLog => $logger->log(
        'settings.updated', 'tenant_settings',
        ['a' => 1, 'b' => 'same', 'c' => null],
        ['a' => 2, 'b' => 'same', 'c' => 'x'],
        entityId: 5,
    ));

    expect($log->old_values)->toBe(['a' => 1, 'c' => null])
        ->and($log->new_values)->toBe(['a' => 2, 'c' => 'x'])
        ->and($log->entity_type)->toBe('tenant_settings')
        ->and($log->entity_id)->toBe(5);
});

test('a model entity fills entity type and id from the model', function (): void {
    $logger = new AuditLogger(Request::create('/'));

    $log = $this->asTenant($this->tenant, fn (): AuditLog => $logger->log('tenant.renamed', $this->tenant, null, ['name' => 'x']));

    expect($log->entity_type)->toBe('tenants')
        ->and($log->entity_id)->toBe($this->tenant->id)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe(['name' => 'x']);
});

test('without an authenticated user the actor is the system', function (): void {
    $logger = new AuditLogger(Request::create('/'));

    $log = $this->asTenant($this->tenant, fn (): AuditLog => $logger->log('x', 'y'));

    expect($log->actor_type)->toBe(ActorType::System)
        ->and($log->actor_id)->toBeNull();
});

test('an authenticated tenant user is recorded as the actor', function (): void {
    $owner = $this->makeOwner();
    $request = Request::create('/');
    $request->setUserResolver(fn (?string $guard = null) => $guard === 'tenant_user' ? $owner : null);
    $logger = new AuditLogger($request);

    $log = $this->asTenant($this->tenant, fn (): AuditLog => $logger->log('x', 'y'));

    expect($log->actor_type)->toBe(ActorType::TenantUser)
        ->and($log->actor_id)->toBe($owner->id);
});

test('request metadata is captured and the user agent is truncated', function (): void {
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.9', 'HTTP_USER_AGENT' => str_repeat('u', 600)]);
    $logger = new AuditLogger($request);

    $log = $this->asTenant($this->tenant, fn (): AuditLog => $logger->log('x', 'y'));

    expect($log->ip)->toBe('10.0.0.9')
        ->and(mb_strlen((string) $log->user_agent))->toBe(490);
});

test('batch ids are uuids shared by the records of one bulk action', function (): void {
    $logger = new AuditLogger(Request::create('/'));
    $batch = $logger->newBatchId();

    $logs = $this->asTenant($this->tenant, fn (): array => [
        $logger->log('bulk.a', 'y', batchId: $batch),
        $logger->log('bulk.b', 'y', batchId: $batch),
    ]);

    expect($batch)->toBeUuid()
        ->and($logs[0]->batch_id)->toBe($batch)
        ->and($logs[1]->batch_id)->toBe($batch);
});
