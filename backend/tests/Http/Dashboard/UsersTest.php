<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\TenantUser;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('owners list the users of their tenant only', function (): void {
    $this->makeOwner($this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/users')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->me->id);
});

test('an owner creates another owner with a lower-cased email', function (): void {
    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'أحمد', 'email' => 'Ahmed@Alnoor.test', 'password' => 'secret123'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ahmed@alnoor.test')
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $this->tenant->id, 'email' => 'ahmed@alnoor.test']);
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'tenant_user.created', 'actor_id' => $this->me->id]);
});

test('user creation validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/users', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [['email' => 'a@b.c', 'password' => 'secret123'], 'name'],
    'invalid email' => [['name' => 'x', 'email' => 'nope', 'password' => 'secret123'], 'email'],
    'short password' => [['name' => 'x', 'email' => 'a@b.c', 'password' => 'short'], 'password'],
]);

test('BR-P03 style uniqueness: the same email is allowed in another tenant but not twice in one', function (): void {
    $this->makeOwner($this->otherTenant(), ['email' => 'shared@x.test']);

    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'x', 'email' => 'shared@x.test', 'password' => 'secret123'])
        ->assertCreated();

    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'y', 'email' => 'shared@x.test', 'password' => 'secret123'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'email_taken');
});

test('an owner cannot disable their own account', function (): void {
    $this->tenantJson('PATCH', "/api/dashboard/users/{$this->me->id}", ['status' => 'disabled'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'cannot_disable_self');
});

test('the last active user of a tenant cannot be disabled', function (): void {
    $second = $this->makeOwner();
    $this->actingAs($second, 'tenant_user');
    $this->me->update(['status' => TenantUser::STATUS_DISABLED]);

    $this->tenantJson('PATCH', "/api/dashboard/users/{$second->id}", ['status' => 'disabled'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'cannot_disable_self');
});

test('an owner disables, renames and resets the password of another user, audited', function (): void {
    $other = $this->makeOwner();

    $this->tenantJson('PATCH', "/api/dashboard/users/{$other->id}", ['status' => 'disabled', 'name' => 'جديد', 'password' => 'newsecret1'])
        ->assertOk()
        ->assertJsonPath('data.status', 'disabled')
        ->assertJsonPath('data.name', 'جديد');

    $log = $this->asTenant($this->tenant, fn () => App\Modules\Audit\Models\AuditLog::query()->latest('id')->first());

    expect($log->action)->toBe('tenant_user.updated')
        ->and($log->new_values)->toMatchArray(['status' => 'disabled', 'name' => 'جديد', 'password' => '***'])
        ->and($log->new_values)->not->toHaveKey('password_hash');
});

test('BR-T04 a user id of another tenant is 404 and untouched', function (): void {
    $foreign = $this->makeOwner($this->otherTenant(), ['name' => 'original']);

    $this->tenantJson('PATCH', "/api/dashboard/users/{$foreign->id}", ['name' => 'hacked'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('tenant_users', ['id' => $foreign->id, 'name' => 'original']);
});
