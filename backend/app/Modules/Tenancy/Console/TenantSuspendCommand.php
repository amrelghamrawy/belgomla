<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Enums\TenantStatus;

final class TenantSuspendCommand extends TenantStatusCommand
{
    protected $signature = 'tenant:suspend {subdomain}';

    protected $description = 'Suspend a tenant (storefront 503, dashboard 403)';

    protected function targetStatus(): TenantStatus
    {
        return TenantStatus::Suspended;
    }
}
