<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Tenancy\Models\Tenant;

/** Platform-level lookup of one tenant by subdomain (CLI, monitoring). */
final readonly class TenantBySubdomainQuery
{
    public function find(string $subdomain): ?Tenant
    {
        return Tenant::query()->where('subdomain', $subdomain)->first();
    }
}
