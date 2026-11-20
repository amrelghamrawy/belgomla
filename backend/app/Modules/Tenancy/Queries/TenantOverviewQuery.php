<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Platform\Queries\VerticalTemplateQuery;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;

/** Platform-level listing of every tenant with its user count (CLI). */
final readonly class TenantOverviewQuery
{
    public function __construct(private VerticalTemplateQuery $verticals) {}

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

        $tenants = Tenant::query()->orderBy('id')->get();
        $verticals = $this->verticals->findMany(array_values(array_unique($tenants->map(fn (Tenant $tenant): int => $tenant->vertical_id)->all())));

        return array_values($tenants->map(fn (Tenant $tenant): array => [
            $tenant->id, $tenant->subdomain, $tenant->name, $tenant->status->value, $verticals[$tenant->vertical_id]->key,
            $userCounts[$tenant->id] ?? 0, $tenant->created_at->toDateString(),
        ])->all());
    }
}
