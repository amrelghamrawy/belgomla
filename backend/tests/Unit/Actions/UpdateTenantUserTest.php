<?php

declare(strict_types=1);

use App\Modules\Tenancy\Actions\UpdateTenantUser;
use App\Modules\Tenancy\Data\TenantUserChanges;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Exceptions\TenantUserConflict;
use Illuminate\Support\Facades\Hash;

test('renames, disables and resets the password with an audit record that masks the password', function (): void {
    $actor = $this->makeOwner();
    $user = $this->makeOwner();

    $updated = $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle(
        $user, new TenantUserChanges('جديد', TenantUserStatus::Disabled, 'newsecret1'), $actor,
    ));

    expect($updated->name)->toBe('جديد')
        ->and($updated->status)->toBe(TenantUserStatus::Disabled)
        ->and(Hash::check('newsecret1', $updated->password))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'tenant_user.updated']);
});

test('D82 a role change is audited on its own with the old and new role', function (): void {
    $actor = $this->makeOwner();
    $user = $this->makeOwner();

    $updated = $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle(
        $user, new TenantUserChanges(null, null, null, TenantUserRole::Warehouse), $actor,
    ));

    expect($updated->role)->toBe(TenantUserRole::Warehouse)
        ->and(App\Modules\Audit\Models\AuditLog::withoutTenantScope()->where('action', 'tenant_user.role_changed')->sole()->only(['entity_id', 'old_values', 'new_values']))->toBe([
            'entity_id' => $user->id, 'old_values' => ['role' => 'owner'], 'new_values' => ['role' => 'warehouse'],
        ]);
});

test('D82 sending the same role writes no role_changed audit', function (): void {
    $actor = $this->makeOwner();
    $user = $this->makeOwner();

    $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle($user, new TenantUserChanges(null, null, null, TenantUserRole::Owner), $actor));

    expect(App\Modules\Audit\Models\AuditLog::withoutTenantScope()->where('action', 'tenant_user.role_changed')->count())->toBe(0);
});

test('D82 the last active owner can be neither demoted nor disabled', function (TenantUserChanges $changes): void {
    $actor = $this->makeOwner(attributes: ['role' => TenantUserRole::Manager]);
    $this->makeOwner(attributes: ['status' => TenantUserStatus::Disabled]);
    $last = $this->makeOwner();

    expect(fn () => $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle($last, $changes, $actor)))
        ->toThrow(function (TenantUserConflict $e): void {
            expect($e->errorCode)->toBe('last_owner');
        });
    expect($last->refresh()->only(['role', 'status']))->toBe(['role' => TenantUserRole::Owner, 'status' => TenantUserStatus::Active]);
})->with([
    'demoted' => [new TenantUserChanges(null, null, null, TenantUserRole::Manager)],
    'disabled' => [new TenantUserChanges(null, TenantUserStatus::Disabled, null)],
]);

test('D82 an owner can be demoted while another active owner remains', function (): void {
    $actor = $this->makeOwner();
    $user = $this->makeOwner();

    $updated = $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle($user, new TenantUserChanges(null, null, null, TenantUserRole::Sales), $actor));

    expect($updated->role)->toBe(TenantUserRole::Sales);
});

test('BR-T06 an owner cannot disable themselves', function (): void {
    $actor = $this->makeOwner();

    $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle(
        $actor, new TenantUserChanges(null, TenantUserStatus::Disabled, null), $actor,
    ));
})->throws(TenantUserConflict::class, 'حسابك الحالي');

test('BR-T06 the last active user cannot be disabled', function (): void {
    $actor = $this->makeOwner(attributes: ['status' => TenantUserStatus::Disabled]);
    $last = $this->makeOwner();

    $this->asTenant($this->tenant, fn () => app(UpdateTenantUser::class)->handle(
        $last, new TenantUserChanges(null, TenantUserStatus::Disabled, null), $actor,
    ));
})->throws(TenantUserConflict::class, 'آخر مستخدم');
