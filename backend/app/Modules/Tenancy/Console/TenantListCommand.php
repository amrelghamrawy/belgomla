<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Console\Command;

class TenantListCommand extends Command
{
    protected $signature = 'tenant:list';

    protected $description = 'List all tenants';

    public function handle(): int
    {
        // No tenant is in context on the CLI, so the tenant scope must be bypassed explicitly.
        $userCounts = TenantUser::withoutTenantScope()
            ->selectRaw('tenant_id, count(*) as c')
            ->groupBy('tenant_id')
            ->pluck('c', 'tenant_id');

        $rows = Tenant::query()->orderBy('id')->get()->map(fn (Tenant $t) => [
            $t->id, $t->subdomain, $t->name, $t->status, $t->vertical,
            $userCounts[$t->id] ?? 0, $t->created_at->toDateString(),
        ]);

        $this->table(['ID', 'Subdomain', 'Name', 'Status', 'Vertical', 'Users', 'Created'], $rows->all());

        return self::SUCCESS;
    }
}
