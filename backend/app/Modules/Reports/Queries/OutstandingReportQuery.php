<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/reports/outstanding (D5 b, D24): per customer that owes something, the delivered orders not
 * fully collected, their total, what was collected and what is outstanding (D59), largest debt first.
 */
final readonly class OutstandingReportQuery
{
    public function __construct(private OutstandingByCustomerQuery $outstanding) {}

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->outstanding->builder()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->addSelect(['customers.name as customer_name', 'customers.phone as customer_phone'])
            ->groupBy('customers.id')
            ->orderByDesc('outstanding')
            ->orderBy('orders.customer_id')
            ->paginate($perPage);
    }
}
