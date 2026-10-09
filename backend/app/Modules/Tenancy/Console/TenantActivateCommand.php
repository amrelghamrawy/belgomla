<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Enums\TenantStatus;

final class TenantActivateCommand extends TenantStatusCommand
{
    protected $signature = 'tenant:activate {subdomain}';

    protected $description = 'Re-activate a suspended tenant';

    protected function targetStatus(): TenantStatus
    {
        return TenantStatus::Active;
    }
}
