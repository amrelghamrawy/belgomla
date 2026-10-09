<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantInput;
use App\Modules\Tenancy\Models\Tenant;

/** Platform operation (CLI): suspend or re-activate a tenant (BR-T07). */
final readonly class SetTenantStatus
{
    public function handle(string $subdomain, TenantStatus $status): Tenant
    {
        $tenant = Tenant::query()->where('subdomain', mb_strtolower($subdomain))->first();

        if ($tenant === null) {
            throw InvalidTenantInput::unknownTenant($subdomain);
        }

        $tenant->update(['status' => $status]);

        return $tenant;
    }
}
