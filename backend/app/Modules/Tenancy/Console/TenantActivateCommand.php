<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Tenant;

class TenantActivateCommand extends TenantStatusCommand
{
    protected $signature = 'tenant:activate {subdomain}';

    protected $description = 'Re-activate a suspended tenant';

    protected function targetStatus(): string
    {
        return Tenant::STATUS_ACTIVE;
    }
}
