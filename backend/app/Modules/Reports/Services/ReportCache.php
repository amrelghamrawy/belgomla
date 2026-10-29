<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Enums\ReportName;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;

/**
 * D94: an advanced report is computed once per tenant, report and filter set and kept for `reports.cache_seconds`
 * (10 minutes) with a TTL only: no write busts it, so a merchant who just recorded something sees it once the entry
 * expires. The entry carries the moment it was computed (`generated_at`, «آخر تحديث»).
 */
final readonly class ReportCache
{
    public function __construct(private TenantContext $context) {}

    /**
     * @template TData
     *
     * @param  array<string, mixed>  $key  everything the figures depend on besides the tenant
     * @param  Closure(): TData  $compute
     * @return array{generated_at: string, data: TData}
     */
    public function remember(ReportName $report, array $key, Closure $compute): array
    {
        $cacheKey = sprintf('reports:%s:%d:%s', $report->value, $this->context->id(), hash('xxh128', json_encode($key, JSON_THROW_ON_ERROR)));

        /** @var array{generated_at: string, data: TData} $entry */
        $entry = Cache::remember($cacheKey, config()->integer('reports.cache_seconds'), fn (): array => [
            'generated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'data' => $compute(),
        ]);

        return $entry;
    }

    /**
     * One page of a paginated report, cached like `remember()` (the page and its size are part of the key): the rows
     * of the page mapped to plain arrays, and the total.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  array<string, mixed>  $key
     * @param  Closure(): QueryBuilder  $query
     * @param  Closure(object): TRow  $map
     * @return array{generated_at: string, page: LengthAwarePaginator<int, TRow>}
     */
    public function paginate(ReportName $report, array $key, Closure $query, Closure $map, int $page, int $perPage): array
    {
        $entry = $this->remember($report, [...$key, 'page' => $page, 'per_page' => $perPage], function () use ($query, $map, $page, $perPage): array {
            $paginator = $query()->paginate($perPage, ['*'], 'page', $page);
            $rows = [];

            foreach ($paginator->items() as $row) {
                $rows[] = $map(is_object($row) ? $row : (object) $row);
            }

            return ['rows' => $rows, 'total' => $paginator->total()];
        });

        return [
            'generated_at' => $entry['generated_at'],
            'page' => new LengthAwarePaginator($entry['data']['rows'], $entry['data']['total'], $perPage, $page, ['path' => Paginator::resolveCurrentPath()]),
        ];
    }
}
