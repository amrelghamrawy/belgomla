<?php

declare(strict_types=1);

use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Services\ReportCache;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/*
| D94: the advanced reports are cached per tenant, report and key for reports.cache_seconds (10 minutes), TTL only.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->calls = 0;
    $this->compute = (fn (): int => ++$this->calls);
});

test('D94 a report is computed once and served from the cache for ten minutes, with the moment it was computed', function (): void {
    $cache = app(ReportCache::class);

    $first = $cache->remember(ReportName::Products, ['a' => 1], $this->compute);
    $this->travel(599)->seconds();
    $second = $cache->remember(ReportName::Products, ['a' => 1], $this->compute);
    $this->travel(2)->seconds();
    $third = $cache->remember(ReportName::Products, ['a' => 1], $this->compute);

    expect($first)->toBe(['generated_at' => '2026-10-10T12:00:00+00:00', 'data' => 1])
        ->and($second)->toBe($first)
        ->and($third)->toBe(['generated_at' => '2026-10-10T12:10:01+00:00', 'data' => 2]);
});

test('D94 another key, report or tenant is computed apart', function (): void {
    $cache = app(ReportCache::class);
    $cache->remember(ReportName::Products, ['a' => 1], $this->compute);

    $cache->remember(ReportName::Products, ['a' => 2], $this->compute);
    $cache->remember(ReportName::Inventory, ['a' => 1], $this->compute);
    app(TenantContext::class)->set($this->otherTenant());
    $cache->remember(ReportName::Products, ['a' => 1], $this->compute);

    expect($this->calls)->toBe(4);
});

test('a cached page keeps its rows, total, size and number', function (): void {
    $cache = app(ReportCache::class);
    $query = fn (): QueryBuilder => DB::query()->fromRaw('generate_series(1, 5) as n')->select('n')->orderBy('n');
    $map = fn (object $row): array => ['n' => (int) $row->n];

    $page = $cache->paginate(ReportName::Customers, [], $query, $map, 2, 2)['page'];
    $again = $cache->paginate(ReportName::Customers, [], fn (): QueryBuilder => throw new LogicException('not cached'), $map, 2, 2)['page'];

    expect($page->items())->toBe([['n' => 3], ['n' => 4]])
        ->and($page->total())->toBe(5)
        ->and($page->perPage())->toBe(2)
        ->and($page->currentPage())->toBe(2)
        ->and($again->items())->toBe($page->items());
});
