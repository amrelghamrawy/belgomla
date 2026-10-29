<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Queries\CollectionFlowQuery;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Services\ReportCache;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * GET /api/dashboard/reports/collections (V2 Task 16): per bucket (Cairo days, D60; Saturday weeks, D58) the live
 * money collected by `collected_at` — payments in cash, payments drawn from customer credit (BR-C07) and refunds
 * (negative, cash or to credit, BR-R04) — their net (what `collected_amount` adds up), next to the net sales delivered in
 * the bucket (SalesAnalyticsQuery: delivered totals less the returns of the bucket); the totals with the collection
 * rate (net collected ÷ net delivered, %; null when nothing was delivered); and the aging of what customers owe now
 * (BR-C06, D59). A customer group filter keeps that group's customers; a category does not apply. Cached (D94).
 *
 * @phpstan-import-type Aging from OutstandingReportQuery
 *
 * @phpstan-type CollectionBucket array{period_start: string, cash: string, credit: string, refunds: string, net: string, delivered: string}
 * @phpstan-type CollectionsReport array{from: string, to: string, group: string, buckets: list<CollectionBucket>, totals: array{cash: string, credit: string, refunds: string, net: string, delivered: string, collection_rate: string|null}, aging: Aging}
 */
final readonly class CollectionsReportQuery
{
    public function __construct(
        private CollectionFlowQuery $flow,
        private SalesAnalyticsQuery $sales,
        private OutstandingReportQuery $outstanding,
        private ReportCache $cache,
    ) {}

    /**
     * @return array{generated_at: string, data: CollectionsReport}
     */
    public function get(ReportFiltersData $filters): array
    {
        $filters = new ReportFiltersData($filters->from, $filters->to, $filters->group, null, $filters->customerGroupId, $filters->timezone);

        return $this->cache->remember(ReportName::Collections, ['filters' => $filters], fn (): array => $this->compute($filters));
    }

    /**
     * @return CollectionsReport
     */
    private function compute(ReportFiltersData $filters): array
    {
        $collected = $this->collected($filters);
        $delivered = array_column($this->sales->period($filters, $filters->from, $filters->to)['buckets'], 'net', 'period_start');
        $buckets = [];
        $totals = ['cash' => '0.00', 'credit' => '0.00', 'refunds' => '0.00', 'net' => '0.00', 'delivered' => '0.00'];

        foreach ($filters->group->periods($filters->from, $filters->to) as $period) {
            [$cash, $credit, $refunds] = $collected[$period] ?? ['0.00', '0.00', '0.00'];
            $bucket = [
                'cash' => $cash,
                'credit' => $credit,
                'refunds' => Money::subtract('0.00', $refunds),
                'net' => Money::subtract(Money::add($cash, $credit), $refunds),
                'delivered' => $delivered[$period] ?? '0.00',
            ];
            $buckets[] = ['period_start' => $period, ...$bucket];

            foreach ($bucket as $key => $amount) {
                $totals[$key] = Money::add($totals[$key], $amount);
            }
        }

        return [
            'from' => $filters->from,
            'to' => $filters->to,
            'group' => $filters->group->value,
            'buckets' => $buckets,
            'totals' => [...$totals, 'collection_rate' => Money::percentOf($totals['net'], $totals['delivered'])],
            'aging' => $this->outstanding->totals($filters->customerGroupId),
        ];
    }

    /**
     * Cash payments, credit payments and refunds per bucket.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function collected(ReportFiltersData $filters): array
    {
        $flow = $this->flow->between(BusinessCalendar::dayStart($filters->from, $filters->timezone), BusinessCalendar::dayEnd($filters->to, $filters->timezone));
        $bucket = $filters->group->bucket("(c.at AT TIME ZONE 'UTC') AT TIME ZONE ?");
        $payment = CollectionKind::Payment->value;
        $rows = $flow->newQuery()
            ->fromSub($flow, 'c')
            ->when($filters->customerGroupId, fn (QueryBuilder $query, int $group): QueryBuilder => $query->where('c.customer_group_id', $group))
            ->selectRaw("to_char({$bucket}, 'YYYY-MM-DD') as period", [$filters->timezone])
            ->selectRaw("coalesce(sum(c.amount) filter (where c.kind = '{$payment}' and c.method = '".CollectionMethod::Cash->value."'), 0) as cash")
            ->selectRaw("coalesce(sum(c.amount) filter (where c.kind = '{$payment}' and c.method = '".CollectionMethod::Credit->value."'), 0) as credit")
            ->selectRaw("coalesce(sum(c.amount) filter (where c.kind = '".CollectionKind::Refund->value."'), 0) as refunds")
            ->groupBy('period')
            ->get();
        $out = [];

        foreach ($rows as $row) {
            /** @var object{period: string, cash: string|int, credit: string|int, refunds: string|int} $row */
            $out[$row->period] = [Money::add('0.00', (string) $row->cash), Money::add('0.00', (string) $row->credit), Money::add('0.00', (string) $row->refunds)];
        }

        return $out;
    }
}
