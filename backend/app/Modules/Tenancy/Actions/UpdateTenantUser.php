<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Data\TenantUserChanges;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Exceptions\TenantUserConflict;
use App\Modules\Tenancy\Models\TenantUser;

/** Rename, disable/enable, or reset the password of a tenant user. */
final readonly class UpdateTenantUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(TenantUser $user, TenantUserChanges $changes, TenantUser $actor): TenantUser
    {
        if ($changes->disables()) {
            $this->guardDisable($user, $actor);
        }

        $values = array_filter([
            'name' => $changes->name,
            'status' => $changes->status,
            'password' => $changes->password,
        ], fn (mixed $value): bool => $value !== null);

        $old = $this->snapshot($user);
        $user->fill($values)->save();
        $new = $this->snapshot($user);

        if ($changes->password !== null) {
            $new['password'] = '***';
        }

        $this->audit->log('tenant_user.updated', $user, $old, $new);

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

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TenantUser $user): array
    {
        return ['name' => $user->name, 'status' => $user->status->value];
    }
}
