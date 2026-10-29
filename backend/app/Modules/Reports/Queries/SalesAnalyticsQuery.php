<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Customers\Queries\CustomerGroupsQuery;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Services\ReportCache;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * GET /api/dashboard/reports/sales-analytics (V2 Task 16, D5 extended, D94): per bucket (Cairo days, D60; Saturday
 * weeks, D58) the delivered orders, their gross (list prices × what went out), the manual discounts (line and order,
 * BR-PR08), the live returns of the bucket (by `returned_at`, BR-R05) and the net = gross − discounts − returns; the
 * totals with the average order (gross − discounts over the orders); the same figures for the period of the same
 * length just before; and the split by section (D68) and by the customers' current group (BR-PR12). Facts come from
 * SalesFactsQuery, so bundles count once, at the bundle line, and component lines never add to the sales. A category
 * filter keeps the lines of that category (and the returns of them), a customer group filter the customers of that group.
 * Cached per tenant and filter set (D94).
 *
 * @phpstan-type Figures array{orders: int, gross: string, discounts: string, returns: string, net: string}
 * @phpstan-type Bucket array{period_start: string, orders: int, gross: string, discounts: string, returns: string, net: string}
 * @phpstan-type Totals array{orders: int, gross: string, discounts: string, returns: string, net: string, average_order: string|null}
 * @phpstan-type Section array{kind: string, label: string, orders: int, gross: string, discounts: string, returns: string, net: string}
 * @phpstan-type GroupRow array{customer_group_id: int|null, name: string|null, orders: int, gross: string, discounts: string, returns: string, net: string}
 * @phpstan-type SalesAnalytics array{from: string, to: string, group: string, buckets: list<Bucket>, totals: Totals, previous: array{from: string, to: string, totals: Totals, buckets: list<array{period_start: string, net: string}>}, by_section: list<Section>, by_customer_group: list<GroupRow>}
 */
final readonly class SalesAnalyticsQuery
{
    public function __construct(
        private SalesFactsQuery $facts,
        private ReportCache $cache,
        private CustomerGroupsQuery $groups,
    ) {}

    /**
     * @return array{generated_at: string, data: SalesAnalytics}
     */
    public function get(ReportFiltersData $filters): array
    {
        return $this->cache->remember(ReportName::SalesAnalytics, ['filters' => $filters], fn (): array => $this->compute($filters));
    }

    /**
     * The buckets of a range (every bucket listed, empty ones as zero) and their totals.
     *
     * @return array{buckets: list<Bucket>, totals: Totals}
     */
    public function period(ReportFiltersData $filters, string $from, string $to): array
    {
        $start = BusinessCalendar::dayStart($from, $filters->timezone);
        $end = BusinessCalendar::dayEnd($to, $filters->timezone);
        $local = "(f.at AT TIME ZONE 'UTC') AT TIME ZONE ?";
        $bucket = "to_char({$filters->group->bucket($local)}, 'YYYY-MM-DD')";
        $sales = $this->figures($this->facts->scoped($this->facts->lines($start, $end), $filters), $bucket, [$filters->timezone]);
        $returns = $this->returned($this->facts->scoped($this->facts->returns($start, $end), $filters), $bucket, [$filters->timezone]);
        $buckets = [];
        $sum = $this->zero();

        foreach ($filters->group->periods($from, $to) as $period) {
            $figures = $this->net($sales[$period] ?? $this->zero(), $returns[$period] ?? '0.00');
            $buckets[] = ['period_start' => $period, ...$figures];
            $sum = $this->add($sum, $figures);
        }

        return ['buckets' => $buckets, 'totals' => $this->totals($sum)];
    }

    /**
     * @return SalesAnalytics
     */
    private function compute(ReportFiltersData $filters): array
    {
        $current = $this->period($filters, $filters->from, $filters->to);
        [$previousFrom, $previousTo] = $this->previousRange($filters);
        $previous = $this->period($filters, $previousFrom, $previousTo);
        $start = BusinessCalendar::dayStart($filters->from, $filters->timezone);
        $end = BusinessCalendar::dayEnd($filters->to, $filters->timezone);

        return [
            'from' => $filters->from,
            'to' => $filters->to,
            'group' => $filters->group->value,
            'buckets' => $current['buckets'],
            'totals' => $current['totals'],
            'previous' => [
                'from' => $previousFrom,
                'to' => $previousTo,
                'totals' => $previous['totals'],
                'buckets' => array_map(fn (array $bucket): array => ['period_start' => $bucket['period_start'], 'net' => $bucket['net']], $previous['buckets']),
            ],
            'by_section' => $this->bySection($filters, $start, $end),
            'by_customer_group' => $this->byCustomerGroup($filters, $start, $end),
        ];
    }

    /**
     * The range of the same number of days that ends the day before `from`.
     *
     * @return array{0: string, 1: string}
     */
    private function previousRange(ReportFiltersData $filters): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters->from, 'UTC') ?: CarbonImmutable::now();
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters->to, 'UTC') ?: $from;
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days)->toDateString(), $from->subDay()->toDateString()];
    }

    /**
     * @return list<Section>
     */
    private function bySection(ReportFiltersData $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sales = $this->figures($this->facts->scoped($this->facts->lines($start, $end), $filters), 'f.kind');
        $returns = $this->returned($this->facts->scoped($this->facts->returns($start, $end), $filters), 'f.kind');
        $sections = [];

        foreach (array_unique([...array_keys($sales), ...array_keys($returns)]) as $kind) {
            $kind = (string) $kind;
            $sections[] = ['kind' => $kind, 'label' => CategoryKind::tryFrom($kind)?->label() ?? $kind, ...$this->net($sales[$kind] ?? $this->zero(), $returns[$kind] ?? '0.00')];
        }

        usort($sections, fn (array $a, array $b): int => Money::compare($b['net'], $a['net']) ?: strcmp($a['kind'], $b['kind']));

        return $sections;
    }

    /**
     * @return list<GroupRow>
     */
    private function byCustomerGroup(ReportFiltersData $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = "coalesce(f.customer_group_id::text, '')";
        $sales = $this->figures($this->facts->scoped($this->facts->lines($start, $end), $filters), $key);
        $returns = $this->returned($this->facts->scoped($this->facts->returns($start, $end), $filters), $key);
        $names = $this->groups->names();
        $rows = [];

        foreach (array_unique([...array_keys($sales), ...array_keys($returns)]) as $id) {
            $id = (string) $id;
            $groupId = $id === '' ? null : (int) $id;
            $rows[] = ['customer_group_id' => $groupId, 'name' => $groupId === null ? null : ($names[$groupId] ?? null), ...$this->net($sales[$id] ?? $this->zero(), $returns[$id] ?? '0.00')];
        }

        usort($rows, fn (array $a, array $b): int => Money::compare($b['net'], $a['net']) ?: ($a['customer_group_id'] ?? 0) <=> ($b['customer_group_id'] ?? 0));

        return $rows;
    }

    /**
     * Orders, gross and discounts of the lines, per value of `$key`.
     *
     * @param  list<string>  $bindings
     * @return array<string, array{orders: int, gross: string, discounts: string}>
     */
    private function figures(QueryBuilder $lines, string $key, array $bindings = []): array
    {
        $rows = $lines
            ->selectRaw("{$key} as k", $bindings)
            ->selectRaw('count(distinct f.order_id) as orders')
            ->selectRaw('round(sum(f.gross), 2) as gross')
            ->selectRaw('round(sum(f.line_discount + f.order_discount), 2) as discounts')
            ->groupBy('k')
            ->get();
        $out = [];

        foreach ($rows as $row) {
            /** @var object{k: string|int, orders: int|string, gross: string, discounts: string} $row */
            $out[(string) $row->k] = ['orders' => (int) $row->orders, 'gross' => Money::add('0.00', $row->gross), 'discounts' => Money::add('0.00', $row->discounts)];
        }

        return $out;
    }

    /**
     * The returned amount per value of `$key`.
     *
     * @param  list<string>  $bindings
     * @return array<string, string>
     */
    private function returned(QueryBuilder $returns, string $key, array $bindings = []): array
    {
        $rows = $returns->selectRaw("{$key} as k", $bindings)->selectRaw('round(sum(f.amount), 2) as amount')->groupBy('k')->get();
        $out = [];

        foreach ($rows as $row) {
            /** @var object{k: string|int, amount: string} $row */
            $out[(string) $row->k] = Money::add('0.00', $row->amount);
        }

        return $out;
    }

    /**
     * @param  array{orders: int, gross: string, discounts: string}  $sales
     * @return Figures
     */
    private function net(array $sales, string $returns): array
    {
        return [...$sales, 'returns' => $returns, 'net' => Money::subtract(Money::subtract($sales['gross'], $sales['discounts']), $returns)];
    }

    /**
     * @return array{orders: int, gross: string, discounts: string}
     */
    private function zero(): array
    {
        return ['orders' => 0, 'gross' => '0.00', 'discounts' => '0.00'];
    }

    /**
     * @param  Figures|array{orders: int, gross: string, discounts: string, returns?: string, net?: string}  $sum
     * @param  Figures  $figures
     * @return Figures
     */
    private function add(array $sum, array $figures): array
    {
        return [
            'orders' => $sum['orders'] + $figures['orders'],
            'gross' => Money::add($sum['gross'], $figures['gross']),
            'discounts' => Money::add($sum['discounts'], $figures['discounts']),
            'returns' => Money::add($sum['returns'] ?? '0.00', $figures['returns']),
            'net' => Money::add($sum['net'] ?? '0.00', $figures['net']),
        ];
    }

    /**
     * @param  Figures|array{orders: int, gross: string, discounts: string}  $sum
     * @return Totals
     */
    private function totals(array $sum): array
    {
        $returns = $sum['returns'] ?? '0.00';

        return [
            'orders' => $sum['orders'],
            'gross' => $sum['gross'],
            'discounts' => $sum['discounts'],
            'returns' => $returns,
            'net' => $sum['net'] ?? Money::subtract(Money::subtract($sum['gross'], $sum['discounts']), $returns),
            'average_order' => $sum['orders'] > 0 ? Money::divide(Money::subtract($sum['gross'], $sum['discounts']), $sum['orders']) : null,
        ];
    }
}
