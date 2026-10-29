<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Services\ReportCache;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/reports/customers (V2 Task 16): per customer who ever placed an order (not cancelled; pre-orders
 * left out, D88) — in the range (Cairo days, D60) the orders delivered, the net revenue (their totals less the returns
 * recorded in the range, BR-R05) and the average order (their totals over their count); over all time the first and
 * last order placed, the average days between orders (null with a single order) and the Cairo days since the last
 * one, dormant after `dormant_days` (default 45); and now what they owe (D59) and what is overdue (BR-C06), with their
 * current group (BR-PR12). View `top`: customers with an order delivered in the range, most net revenue first; view
 * `dormant`: dormant customers, the longest silent first. A customer group filter keeps that group. Cached per
 * tenant, filter set and page (D94).
 *
 * @phpstan-type CustomerRow array{customer: array{id: int, name: string, phone: string}, customer_group: array{id: int, name: string}|null, orders: int, revenue: string, average_order: string|null, first_order_at: string, last_order_at: string, lifetime_orders: int, average_interval_days: float|null, days_since_last_order: int, dormant: bool, outstanding: string, overdue: string}
 */
final readonly class CustomerPatternsQuery
{
    public function __construct(
        private OutstandingByCustomerQuery $outstanding,
        private ReportCache $cache,
    ) {}

    /**
     * @return array{generated_at: string, page: LengthAwarePaginator<int, CustomerRow>}
     */
    public function paginate(ReportFiltersData $filters, CustomerView $view, int $dormantDays, int $page, int $perPage): array
    {
        return $this->cache->paginate(
            ReportName::Customers,
            ['filters' => $filters, 'view' => $view->value, 'dormant_days' => $dormantDays],
            fn (): QueryBuilder => $this->builder($filters, $view, $dormantDays),
            fn (object $row): array => $this->row($row, $dormantDays),
            $page,
            $perPage,
        );
    }

    /**
     * Every row, for the export (not cached).
     *
     * @return Generator<int, CustomerRow>
     */
    public function rows(ReportFiltersData $filters, CustomerView $view, int $dormantDays): Generator
    {
        foreach ($this->builder($filters, $view, $dormantDays)->cursor() as $row) {
            yield $this->row($row, $dormantDays);
        }
    }

    public function builder(ReportFiltersData $filters, CustomerView $view, int $dormantDays): QueryBuilder
    {
        $start = BusinessCalendar::dayStart($filters->from, $filters->timezone);
        $end = BusinessCalendar::dayEnd($filters->to, $filters->timezone);
        $daysSince = "(?::date - ((l.last_order_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date)";
        $daysBindings = [BusinessCalendar::today($filters->timezone), $filters->timezone];
        $lifetime = Order::query()->toBase()
            ->where('orders.kind', OrderKind::Order->value)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->select('orders.customer_id')
            ->selectRaw('count(*) as lifetime_orders, min(orders.placed_at) as first_order_at, max(orders.placed_at) as last_order_at')
            ->groupBy('orders.customer_id');

        $query = $lifetime->newQuery()
            ->fromSub($lifetime, 'l')
            ->join('customers', 'customers.id', '=', 'l.customer_id')
            ->leftJoin('customer_groups', 'customer_groups.id', '=', 'customers.customer_group_id')
            ->leftJoinSub($this->delivered($start, $end), 'd', 'd.customer_id', '=', 'l.customer_id')
            ->leftJoinSub($this->returned($start, $end), 'r', 'r.customer_id', '=', 'l.customer_id')
            ->leftJoinSub($this->owing(), 'o', 'o.customer_id', '=', 'l.customer_id')
            ->when($filters->customerGroupId, fn (QueryBuilder $query, int $group): QueryBuilder => $query->where('customers.customer_group_id', $group))
            ->select(['customers.id as customer_id', 'customers.name as customer_name', 'customers.phone as customer_phone', 'customer_groups.id as group_id', 'customer_groups.name as group_name', 'l.lifetime_orders', 'l.first_order_at', 'l.last_order_at'])
            ->selectRaw('coalesce(d.orders, 0) as orders, coalesce(d.total, 0) as delivered_total')
            ->selectRaw('round(coalesce(d.total, 0) - coalesce(r.total, 0), 2) as revenue')
            ->selectRaw('case when l.lifetime_orders > 1 then round(extract(epoch from (l.last_order_at - l.first_order_at)) / 86400.0 / (l.lifetime_orders - 1), 1) end as average_interval_days')
            ->selectRaw("{$daysSince} as days_since_last_order", $daysBindings)
            ->selectRaw('coalesce(o.outstanding, 0) as outstanding, coalesce(o.overdue, 0) as overdue');

        return match ($view) {
            CustomerView::Top => $query->whereRaw('coalesce(d.orders, 0) > 0')->orderByDesc('revenue')->orderByDesc('orders')->orderBy('customers.id'),
            CustomerView::Dormant => $query->whereRaw("{$daysSince} > ?", [...$daysBindings, $dormantDays])->orderBy('l.last_order_at')->orderBy('customers.id'),
        };
    }

    /** Per customer: the orders delivered in the range and their totals. */
    private function delivered(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return Order::query()->toBase()
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.kind', OrderKind::Order->value)
            ->whereBetween('orders.delivered_at', [$start, $end])
            ->select('orders.customer_id')
            ->selectRaw('count(*) as orders, sum(orders.order_total) as total')
            ->groupBy('orders.customer_id');
    }

    /** Per customer: the live returns recorded in the range (BR-R05). */
    private function returned(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return OrderReturn::query()->toBase()
            ->join('orders', 'orders.id', '=', 'order_returns.order_id')
            ->whereNull('order_returns.voided_at')
            ->whereBetween('order_returns.returned_at', [$start, $end])
            ->select('orders.customer_id')
            ->selectRaw('sum(order_returns.total) as total')
            ->groupBy('orders.customer_id');
    }

    /** Per customer: what they owe now (D59) and the part of it past due (BR-C06; delivered_at without a due date). */
    private function owing(): QueryBuilder
    {
        return $this->outstanding->builder()
            ->selectRaw('sum(case when coalesce(orders.due_at, orders.delivered_at) < ? then orders.order_total - orders.returned_total - orders.collected_amount else 0 end) as overdue', [CarbonImmutable::now()]);
    }

    /**
     * @return CustomerRow
     */
    private function row(object $row, int $dormantDays): array
    {
        /** @var object{customer_id: int|string, customer_name: string, customer_phone: string, group_id: int|string|null, group_name: string|null, lifetime_orders: int|string, first_order_at: string, last_order_at: string, orders: int|string, delivered_total: string|int, revenue: string, average_interval_days: string|null, days_since_last_order: int|string, outstanding: string|int, overdue: string|int} $row */
        $orders = (int) $row->orders;
        $days = (int) $row->days_since_last_order;

        return [
            'customer' => ['id' => (int) $row->customer_id, 'name' => $row->customer_name, 'phone' => $row->customer_phone],
            'customer_group' => $row->group_id === null ? null : ['id' => (int) $row->group_id, 'name' => (string) $row->group_name],
            'orders' => $orders,
            'revenue' => Money::add('0.00', $row->revenue),
            'average_order' => $orders > 0 ? Money::divide((string) $row->delivered_total, $orders) : null,
            'first_order_at' => CarbonImmutable::parse($row->first_order_at, 'UTC')->toIso8601String(),
            'last_order_at' => CarbonImmutable::parse($row->last_order_at, 'UTC')->toIso8601String(),
            'lifetime_orders' => (int) $row->lifetime_orders,
            'average_interval_days' => $row->average_interval_days === null ? null : (float) $row->average_interval_days,
            'days_since_last_order' => $days,
            'dormant' => $days > $dormantDays,
            'outstanding' => Money::add('0.00', (string) $row->outstanding),
            'overdue' => Money::add('0.00', (string) $row->overdue),
        ];
    }
}
