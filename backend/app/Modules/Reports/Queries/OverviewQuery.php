<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Inventory\Queries\LowStockQuery;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Support\BusinessCalendar;
use App\Support\Money;

/**
 * GET /api/dashboard/reports/overview (D5): orders per status, today's orders (placed today in business time, D60;
 * the total leaves cancelled ones out), pending orders past the stale threshold (BR-INV07), what customers owe
 * (D59) and how many SKUs are low on stock (D36).
 */
final readonly class OverviewQuery
{
    public function __construct(
        private DashboardOrdersQuery $orders,
        private OutstandingByCustomerQuery $outstanding,
        private LowStockQuery $lowStock,
    ) {}

    /**
     * @return array{orders_by_status: array<string, int>, today: array{orders: int, total: string}, stale_pending: int, outstanding_total: string, low_stock_count: int}
     */
    public function get(string $timezone): array
    {
        return [
            'orders_by_status' => $this->byStatus(),
            'today' => $this->today($timezone),
            'stale_pending' => Order::query()->where('status', OrderStatus::Pending)->where('placed_at', '<', $this->orders->staleBefore())->count(),
            'outstanding_total' => $this->outstanding->total(),
            'low_stock_count' => $this->lowStock->builder()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function byStatus(): array
    {
        $counts = Order::query()->toBase()->select('status')->selectRaw('count(*) as orders')->groupBy('status')->pluck('orders', 'status');
        $out = [];

        foreach (OrderStatus::cases() as $status) {
            $count = $counts->get($status->value, 0);
            $out[$status->value] = is_numeric($count) ? (int) $count : 0;
        }

        return $out;
    }

    /**
     * @return array{orders: int, total: string}
     */
    private function today(string $timezone): array
    {
        $day = BusinessCalendar::today($timezone);
        $placed = Order::query()->whereBetween('placed_at', [BusinessCalendar::dayStart($day, $timezone), BusinessCalendar::dayEnd($day, $timezone)]);
        $total = (clone $placed)->where('status', '!=', OrderStatus::Cancelled)->sum('order_total');

        return [
            'orders' => $placed->count(),
            'total' => Money::add('0.00', (string) $total),
        ];
    }
}
