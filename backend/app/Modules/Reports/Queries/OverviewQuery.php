<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Collections\Queries\OverdueQuery;
use App\Modules\Inventory\Queries\LowStockQuery;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Support\BusinessCalendar;
use App\Support\Money;

/**
 * GET /api/dashboard/reports/overview (D5): orders per status, today's orders (placed today in business time, D60;
 * the total leaves cancelled ones out and takes off today's live returns, BR-R05, which are also given apart), pending orders past the stale threshold (BR-INV07), what customers owe
 * (D59), what is overdue and for how many customers (BR-C06), and how many SKUs are low on stock (D36). Pre-orders are
 * left out: they are not orders to handle yet (BR-O14, D88).
 */
final readonly class OverviewQuery
{
    public function __construct(
        private DashboardOrdersQuery $orders,
        private OutstandingByCustomerQuery $outstanding,
        private OverdueQuery $overdue,
        private LowStockQuery $lowStock,
    ) {}

    /**
     * @return array{orders_by_status: array<string, int>, today: array{orders: int, total: string, returns: string}, stale_pending: int, outstanding_total: string, overdue_total: string, overdue_customers: int, low_stock_count: int}
     */
    public function get(string $timezone): array
    {
        return [
            'orders_by_status' => $this->byStatus(),
            'today' => $this->today($timezone),
            'stale_pending' => Order::query()->where('kind', OrderKind::Order)->where('status', OrderStatus::Pending)->where('placed_at', '<', $this->orders->pendingClock()->staleBefore)->count(),
            'outstanding_total' => $this->outstanding->total(),
            'overdue_total' => $this->overdue->total(),
            'overdue_customers' => $this->overdue->customersCount(),
            'low_stock_count' => $this->lowStock->builder()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function byStatus(): array
    {
        $counts = Order::query()->where('kind', OrderKind::Order)->toBase()->select('status')->selectRaw('count(*) as orders')->groupBy('status')->pluck('orders', 'status');
        $out = [];

        foreach (OrderStatus::cases() as $status) {
            $count = $counts->get($status->value, 0);
            $out[$status->value] = is_numeric($count) ? (int) $count : 0;
        }

        return $out;
    }

    /**
     * @return array{orders: int, total: string, returns: string}
     */
    private function today(string $timezone): array
    {
        $day = BusinessCalendar::today($timezone);
        $placed = Order::query()->where('kind', OrderKind::Order)->whereBetween('placed_at', [BusinessCalendar::dayStart($day, $timezone), BusinessCalendar::dayEnd($day, $timezone)]);
        $total = (clone $placed)->where('status', '!=', OrderStatus::Cancelled)->sum('order_total');
        $returns = Money::add('0.00', (string) OrderReturn::query()
            ->whereNull('voided_at')
            ->whereBetween('returned_at', [BusinessCalendar::dayStart($day, $timezone), BusinessCalendar::dayEnd($day, $timezone)])
            ->sum('total'));

        return [
            'orders' => $placed->count(),
            'total' => Money::subtract(Money::add('0.00', (string) $total), $returns),
            'returns' => $returns,
        ];
    }
}
