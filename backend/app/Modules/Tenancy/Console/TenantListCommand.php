<?php

declare(strict_types=1);

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
        /** @var array<int, int> $userCounts */
        $userCounts = TenantUser::withoutTenantScope()
            ->selectRaw('tenant_id, count(*) as c')
            ->groupBy('tenant_id')
            ->pluck('c', 'tenant_id')
            ->map(fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
            ->all();

        $rows = Tenant::query()->orderBy('id')->get()->map(fn (Tenant $tenant): array => [
            $tenant->id, $tenant->subdomain, $tenant->name, $tenant->status, $tenant->vertical,
            $userCounts[$tenant->id] ?? 0, $tenant->created_at?->toDateString() ?? '',
        ]);

        $this->table(['ID', 'Subdomain', 'Name', 'Status', 'Vertical', 'Users', 'Created'], $rows->all());

        return self::SUCCESS;
    }
}
