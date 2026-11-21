<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Data\TenantUserChanges;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Exceptions\TenantUserConflict;
use App\Modules\Tenancy\Models\TenantUser;

/**
 * Rename, disable/enable, change the role of (BR-T06, D82), set or clear the phone of (D112), or reset the password
 * of a tenant user. The last active
 * owner can be neither demoted nor disabled (`409 last_owner`); a role change is audited `tenant_user.role_changed`.
 */
final readonly class UpdateTenantUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(TenantUser $user, TenantUserChanges $changes, TenantUser $actor): TenantUser
    {
        if ($changes->disables()) {
            $this->guardDisable($user, $actor);
        }

        if ($changes->disables() || ($changes->role instanceof TenantUserRole && $changes->role !== TenantUserRole::Owner)) {
            $this->guardLastOwner($user);
        }

        $oldRole = $user->role;

        $values = array_filter([
            'name' => $changes->name,
            'status' => $changes->status,
            'password' => $changes->password,
            'role' => $changes->role,
        ], fn (mixed $value): bool => $value !== null);

        if ($changes->phoneSent) {
            $values['phone'] = $changes->phone;
        }

        $old = $this->snapshot($user);
        $user->fill($values)->save();
        $new = $this->snapshot($user);

        if ($changes->password !== null) {
            $new['password'] = '***';
        }

        $this->audit->log('tenant_user.updated', $user, $old, $new);

        if ($user->role !== $oldRole) {
            $this->audit->log('tenant_user.role_changed', $user, ['role' => $oldRole->value], ['role' => $user->role->value]);
        }

        return $user;
    }

    private function guardDisable(TenantUser $user, TenantUser $actor): void
    {
        if ($user->is($actor)) {
            throw TenantUserConflict::cannotDisableSelf();
        }

        $othersActive = TenantUser::query()
            ->where('status', TenantUserStatus::Active)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $othersActive) {
            throw TenantUserConflict::lastActiveUser();
        }
    }

    private function guardLastOwner(TenantUser $user): void
    {
        if ($user->role !== TenantUserRole::Owner || $user->status !== TenantUserStatus::Active) {
            return;
        }

        $otherOwners = TenantUser::query()
            ->where('role', TenantUserRole::Owner)
            ->where('status', TenantUserStatus::Active)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $otherOwners) {
            throw TenantUserConflict::lastOwner();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TenantUser $user): array
    {
        return ['name' => $user->name, 'phone' => $user->phone, 'status' => $user->status->value];
    }
}
