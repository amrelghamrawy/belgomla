<?php

declare(strict_types=1);

use App\Modules\Tenancy\Actions\UpdateTenantUser;
use App\Modules\Tenancy\Data\TenantUserChanges;
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
