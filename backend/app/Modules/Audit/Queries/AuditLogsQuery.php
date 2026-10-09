<?php

declare(strict_types=1);

namespace App\Modules\Audit\Queries;

use App\Modules\Audit\Data\AuditLogFiltersData;
use App\Modules\Audit\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/audit (08-api-contract.md §12, BR-L): the tenant's audit trail, newest first. An entity's
 * timeline uses the `(tenant_id, entity_type, entity_id, created_at)` index and a bulk action's rows the
 * `(tenant_id, batch_id)` one (07-domain-model.md §13).
 */
final readonly class AuditLogsQuery
{
    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(AuditLogFiltersData $filters, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->when($filters->entityType, fn (Builder $query, string $type): Builder => $query->where('entity_type', $type))
            ->when($filters->entityId, fn (Builder $query, int $id): Builder => $query->where('entity_id', $id))
            ->when($filters->actorId, fn (Builder $query, int $id): Builder => $query->where('actor_id', $id))
            ->when($filters->from, fn (Builder $query, CarbonImmutable $from): Builder => $query->where('created_at', '>=', $from))
            ->when($filters->to, fn (Builder $query, CarbonImmutable $to): Builder => $query->where('created_at', '<=', $to))
            ->when($filters->batchId, fn (Builder $query, string $batch): Builder => $query->where('batch_id', $batch))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
