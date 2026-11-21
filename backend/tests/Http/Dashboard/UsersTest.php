<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantUserStatus;

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

test('D112 a user is created with a mobile for the password reset code, stored in E.164', function (): void {
    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'أحمد', 'email' => 'ahmed@alnoor.test', 'password' => 'secret123', 'phone' => '0101 234 5678'])
        ->assertCreated()
        ->assertJsonPath('data.phone', '+201012345678');

    expect(AuditLog::query()->where('action', 'tenant_user.created')->sole()->new_values)->toMatchArray(['phone' => '+201012345678']);
});

test('D112 a user without a phone has phone null', function (): void {
    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'أحمد', 'email' => 'ahmed@alnoor.test', 'password' => 'secret123'])
        ->assertCreated()
        ->assertJsonPath('data.phone', null);
});

test('D112 an owner sets, keeps and clears a user\'s phone, audited', function (): void {
    $user = $this->makeOwner();

    $this->tenantJson('PATCH', "/api/dashboard/users/{$user->id}", ['phone' => '+20 112 345 6789'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+201123456789');

    $this->tenantJson('PATCH', "/api/dashboard/users/{$user->id}", ['name' => 'جديد'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+201123456789');

    $this->tenantJson('PATCH', "/api/dashboard/users/{$user->id}", ['phone' => null])
        ->assertOk()
        ->assertJsonPath('data.phone', null);

    $log = $this->asTenant($this->tenant, fn () => AuditLog::query()->latest('id')->first());

    expect($log->old_values)->toMatchArray(['phone' => '+201123456789'])
        ->and($log->new_values)->toMatchArray(['phone' => null]);
});

test('D112 an owner sets their own phone', function (): void {
    $this->tenantJson('PATCH', "/api/dashboard/users/{$this->me->id}", ['phone' => '01512345678'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+201512345678');
});

test('D112 a phone that is not an Egyptian mobile is refused', function (string $method): void {
    $uri = $method === 'POST' ? '/api/dashboard/users' : "/api/dashboard/users/{$this->me->id}";

    $this->tenantJson($method, $uri, ['name' => 'س', 'email' => 's@alnoor.test', 'password' => 'secret123', 'phone' => '0223456789'])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.phone.0', 'رقم الهاتف يجب أن يكون رقم موبايل مصري صحيح.');
})->with(['POST', 'PATCH']);

test('D82 an owner creates a user with a role, audited with it', function (): void {
    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'سارة', 'email' => 'sara@alnoor.test', 'password' => 'secret123', 'role' => 'accountant'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'accountant')
        ->assertJsonPath('data.role_label', 'محاسب');

    expect(AuditLog::query()->where('action', 'tenant_user.created')->sole()->new_values)->toMatchArray(['role' => 'accountant']);
});

test('D82 an owner changes a user\'s role', function (): void {
    $user = $this->makeOwner(attributes: ['role' => App\Modules\Tenancy\Enums\TenantUserRole::Sales]);

    $this->tenantJson('PATCH', "/api/dashboard/users/{$user->id}", ['role' => 'warehouse'])
        ->assertOk()
        ->assertJsonPath('data.role', 'warehouse')
        ->assertJsonPath('data.role_label', 'مخزن');
});

test('D82 the last active owner cannot demote themselves', function (): void {
    $this->tenantJson('PATCH', "/api/dashboard/users/{$this->me->id}", ['role' => 'manager'])
        ->assertConflict()
        ->assertExactJson(['error' => ['code' => 'last_owner', 'message' => 'لا يمكن تغيير دور آخر مالك نشط أو تعطيله']]);
});

test('D82 an unknown role is refused', function (string $method): void {
    $uri = $method === 'POST' ? '/api/dashboard/users' : "/api/dashboard/users/{$this->me->id}";

    $this->tenantJson($method, $uri, ['name' => 'س', 'email' => 's@alnoor.test', 'password' => 'secret123', 'role' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['role']]]);
})->with(['POST', 'PATCH']);

test('user creation validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/users', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [['email' => 'a@b.c', 'password' => 'secret123'], 'name'],
    'invalid email' => [['name' => 'x', 'email' => 'nope', 'password' => 'secret123'], 'email'],
    'short password' => [['name' => 'x', 'email' => 'a@b.c', 'password' => 'short'], 'password'],
]);

test('the same email is allowed in another tenant but not twice in one', function (): void {
    $this->makeOwner($this->otherTenant(), ['email' => 'shared@x.test']);

    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'x', 'email' => 'shared@x.test', 'password' => 'secret123'])
        ->assertCreated();

    $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'y', 'email' => 'shared@x.test', 'password' => 'secret123'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'email_taken');
});

test('an unknown status is rejected on update', function (): void {
    $other = $this->makeOwner();

    $this->tenantJson('PATCH', "/api/dashboard/users/{$other->id}", ['status' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['status']]]);
});

test('an owner cannot disable their own account', function (): void {
    $this->tenantJson('PATCH', "/api/dashboard/users/{$this->me->id}", ['status' => 'disabled'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'cannot_disable_self');
});

test('the last active user of a tenant cannot be disabled', function (): void {
    $second = $this->makeOwner();
    $this->me->update(['status' => TenantUserStatus::Disabled]);

    $this->tenantJson('PATCH', "/api/dashboard/users/{$second->id}", ['status' => 'disabled'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'last_active_user');
});

test('an owner disables, renames and resets the password of another user, audited', function (): void {
    $other = $this->makeOwner();

    $this->tenantJson('PATCH', "/api/dashboard/users/{$other->id}", ['status' => 'disabled', 'name' => 'جديد', 'password' => 'newsecret1'])
        ->assertOk()
        ->assertJsonPath('data.status', 'disabled')
        ->assertJsonPath('data.name', 'جديد');

    $log = $this->asTenant($this->tenant, fn () => AuditLog::query()->latest('id')->first());

    expect($log->action)->toBe('tenant_user.updated')
        ->and($log->new_values)->toMatchArray(['status' => 'disabled', 'name' => 'جديد', 'password' => '***'])
        ->and($log->old_values)->toMatchArray(['status' => 'active']);
});

test('BR-T04 a user id of another tenant is 404 and untouched', function (): void {
    $foreign = $this->makeOwner($this->otherTenant(), ['name' => 'original']);

    $this->tenantJson('PATCH', "/api/dashboard/users/{$foreign->id}", ['name' => 'hacked'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('tenant_users', ['id' => $foreign->id, 'name' => 'original']);
});
