<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Queries\TenantOverviewQuery;
use Illuminate\Console\Command;

final class TenantListCommand extends Command
{
    protected $signature = 'tenant:list';

    protected $description = 'List all tenants';

    public function handle(TenantOverviewQuery $overview): int
    {
        $this->table(['ID', 'Subdomain', 'Name', 'Status', 'Vertical', 'Users', 'Created'], $overview->rows());

        return self::SUCCESS;
    }
}
