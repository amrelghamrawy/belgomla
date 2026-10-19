<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/reports/outstanding (D5 b, D24): per customer that owes something, the delivered orders not
 * fully collected, their total, what was collected and what is outstanding (D59), largest debt first; the outstanding
 * aged by days past each order's due date (BR-C06; delivered_at for orders without one): not due yet, 1–30, 31–60,
 * 61–90 and over 90 days, plus the overdue sum.
 */
final readonly class OutstandingReportQuery
{
    public function __construct(private OutstandingByCustomerQuery $outstanding) {}

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        $now = CarbonImmutable::now();
        $due = 'coalesce(orders.due_at, orders.delivered_at)';
        $net = '(orders.order_total - orders.returned_total - orders.collected_amount)';

        return $this->outstanding->builder()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->addSelect(['customers.name as customer_name', 'customers.phone as customer_phone'])
            ->selectRaw("sum(case when {$due} >= ? then {$net} else 0 end) as aging_current", [$now])
            ->selectRaw("sum(case when {$due} < ? and {$due} >= ? then {$net} else 0 end) as aging_0_30", [$now, $now->subDays(30)])
            ->selectRaw("sum(case when {$due} < ? and {$due} >= ? then {$net} else 0 end) as aging_31_60", [$now->subDays(30), $now->subDays(60)])
            ->selectRaw("sum(case when {$due} < ? and {$due} >= ? then {$net} else 0 end) as aging_61_90", [$now->subDays(60), $now->subDays(90)])
            ->selectRaw("sum(case when {$due} < ? then {$net} else 0 end) as aging_over_90", [$now->subDays(90)])
            ->selectRaw("sum(case when {$due} < ? then {$net} else 0 end) as overdue", [$now])
            ->groupBy('customers.id')
            ->orderByDesc('outstanding')
            ->orderBy('orders.customer_id')
            ->paginate($perPage);
    }
}
