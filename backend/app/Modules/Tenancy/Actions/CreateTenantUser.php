<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Data\TenantUserData;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Exceptions\TenantUserConflict;
use App\Modules\Tenancy\Models\TenantUser;

/** BR-T06: adds another owner to the current tenant. */
final readonly class CreateTenantUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(TenantUserData $data): TenantUser
    {
        if (TenantUser::query()->where('email', $data->email)->exists()) {
            throw TenantUserConflict::emailTaken();
        }

        $user = TenantUser::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
            'role' => TenantUserRole::Owner,
        ]);

        $this->audit->log('tenant_user.created', $user, null, ['name' => $user->name, 'email' => $user->email]);

        return $user;
    }
}
