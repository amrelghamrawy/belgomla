<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Contracts\Auth\Factory as Auth;

/**
 * BR-T06, D82: what the signed-in dashboard user may do, for modules that may not touch the TenantUser model
 * (e.g. the notifications list, which shows each role only what it covers). None without a dashboard user.
 */
final readonly class CurrentPermissionsQuery
{
    public function __construct(private Auth $auth) {}

    /**
     * @return list<Permission>
     */
    public function get(): array
    {
        $user = $this->auth->guard('tenant_user')->user();

        return $user instanceof TenantUser ? $user->role->permissions() : [];
    }

    /** The signed-in dashboard user's role, for the few rules tied to a role rather than a permission. */
    public function role(): ?TenantUserRole
    {
        $user = $this->auth->guard('tenant_user')->user();

        return $user instanceof TenantUser ? $user->role : null;
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->get(), true);
    }
}
