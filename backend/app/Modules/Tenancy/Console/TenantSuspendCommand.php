<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Tenant;

class TenantSuspendCommand extends TenantStatusCommand
{
    protected $signature = 'tenant:suspend {subdomain}';

    protected $description = 'Suspend a tenant (storefront 503, dashboard 403)';

    protected function targetStatus(): string
    {
        return Tenant::STATUS_SUSPENDED;
    }
}
