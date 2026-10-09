<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;

/** Platform-level listing of every tenant with its user count (CLI). */
final readonly class TenantOverviewQuery
{
    /**
     * @return list<array{0: int, 1: string, 2: string, 3: string, 4: string, 5: int, 6: string}>
     */
    public function rows(): array
    {
        // No tenant is in context on the CLI, so the tenant scope must be bypassed explicitly.
        /** @var array<int, int> $userCounts */
        $userCounts = TenantUser::withoutTenantScope()
            ->selectRaw('tenant_id, count(*) as c')
            ->groupBy('tenant_id')
            ->pluck('c', 'tenant_id')
            ->map(fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
            ->all();

        return array_values(Tenant::query()->orderBy('id')->get()->map(fn (Tenant $tenant): array => [
            $tenant->id, $tenant->subdomain, $tenant->name, $tenant->status->value, $tenant->vertical->value,
            $userCounts[$tenant->id] ?? 0, $tenant->created_at->toDateString(),
        ])->all());
    }
}
