<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * GET /api/dashboard/reports/outstanding (D5 b, D24): per customer that owes something, the delivered orders not
 * fully collected, their total, what was collected and what is outstanding (D59), largest debt first; the outstanding
 * aged by days past each order's due date (BR-C06; delivered_at for orders without one): not due yet, 1–30, 31–60,
 * 61–90 and over 90 days, plus the overdue sum. `totals()` is the same aging over every customer (the collections
 * report's snapshot, V2 Task 16).
 *
 * @phpstan-type Aging array{current: string, '0_30': string, '31_60': string, '61_90': string, over_90: string, overdue: string, outstanding: string}
 */
final readonly class OutstandingReportQuery
{
    private const string DUE = 'coalesce(orders.due_at, orders.delivered_at)';

    private const string NET = '(orders.order_total - orders.returned_total - orders.collected_amount)';

    public function __construct(private OutstandingByCustomerQuery $outstanding) {}

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->aged($this->outstanding->builder())
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->addSelect(['customers.name as customer_name', 'customers.phone as customer_phone'])
            ->groupBy('customers.id')
            ->orderByDesc('outstanding')
            ->orderBy('orders.customer_id')
            ->paginate($perPage);
    }

    /**
     * The aging, overdue and outstanding summed over every customer, or the customers of one group.
     *
     * @return Aging
     */
    public function totals(?int $customerGroupId = null): array
    {
        $rows = $this->aged($this->outstanding->builder())
            ->when($customerGroupId, fn (QueryBuilder $query, int $group): QueryBuilder => $query
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('customers.customer_group_id', $group));
        $sum = $rows->newQuery()
            ->fromSub($rows, 'a')
            ->selectRaw('coalesce(sum(a.aging_current), 0) as current, coalesce(sum(a.aging_0_30), 0) as d0_30, coalesce(sum(a.aging_31_60), 0) as d31_60')
            ->selectRaw('coalesce(sum(a.aging_61_90), 0) as d61_90, coalesce(sum(a.aging_over_90), 0) as over_90, coalesce(sum(a.overdue), 0) as overdue, coalesce(sum(a.outstanding), 0) as outstanding')
            ->first();
        /** @var object{current: string|int, d0_30: string|int, d31_60: string|int, d61_90: string|int, over_90: string|int, overdue: string|int, outstanding: string|int} $sum */

        return [
            'current' => Money::add('0.00', (string) $sum->current),
            '0_30' => Money::add('0.00', (string) $sum->d0_30),
            '31_60' => Money::add('0.00', (string) $sum->d31_60),
            '61_90' => Money::add('0.00', (string) $sum->d61_90),
            'over_90' => Money::add('0.00', (string) $sum->over_90),
            'overdue' => Money::add('0.00', (string) $sum->overdue),
            'outstanding' => Money::add('0.00', (string) $sum->outstanding),
        ];
    }

    /** The per-customer owing rows with their aging buckets and overdue sum. */
    private function aged(QueryBuilder $owing): QueryBuilder
    {
        $now = CarbonImmutable::now();
        $due = self::DUE;
        $net = self::NET;

        return $owing
            ->selectRaw("sum(case when {$due} >= ? then {$net} else 0 end) as aging_current", [$now])
            ->selectRaw("sum(case when {$due} < ? and {$due} >= ? then {$net} else 0 end) as aging_0_30", [$now, $now->subDays(30)])
            ->selectRaw("sum(case when {$due} < ? and {$due} >= ? then {$net} else 0 end) as aging_31_60", [$now->subDays(30), $now->subDays(60)])
            ->selectRaw("sum(case when {$due} < ? and {$due} >= ? then {$net} else 0 end) as aging_61_90", [$now->subDays(60), $now->subDays(90)])
            ->selectRaw("sum(case when {$due} < ? then {$net} else 0 end) as aging_over_90", [$now->subDays(90)])
            ->selectRaw("sum(case when {$due} < ? then {$net} else 0 end) as overdue", [$now]);
    }
}
