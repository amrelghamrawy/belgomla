<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Reports\Data\SalesReportData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * GET /api/dashboard/reports/sales (D5 a): delivered orders counted and summed per bucket of `delivered_at`, in
 * business days (D60). Weeks start on Saturday (D58): Postgres truncates to ISO Monday weeks, so the day is shifted
 * by two before truncating and back after. Every bucket of the range is listed, empty ones as zero.
 */
final readonly class SalesQuery
{
    /**
     * @return array{buckets: list<array{period_start: string, orders: int, total: string}>, orders: int, total: string}
     */
    public function get(SalesReportData $report): array
    {
        $sales = $this->sums($report);
        $buckets = [];
        $orders = 0;
        $total = '0.00';

        foreach ($this->periods($report) as $period) {
            [$count, $sum] = $sales[$period] ?? [0, '0.00'];
            $buckets[] = ['period_start' => $period, 'orders' => $count, 'total' => $sum];
            $orders += $count;
            $total = Money::add($total, $sum);
        }

        return ['buckets' => $buckets, 'orders' => $orders, 'total' => $total];
    }

    /**
     * @return array<string, array{0: int, 1: string}> period start (Y-m-d) => [orders, total]
     */
    private function sums(SalesReportData $report): array
    {
        $local = "(orders.delivered_at AT TIME ZONE 'UTC') AT TIME ZONE ?";
        $bucket = match ($report->group) {
            SalesGrouping::Day => "date_trunc('day', {$local})",
            SalesGrouping::Week => "date_trunc('week', {$local} + interval '2 days') - interval '2 days'",
            SalesGrouping::Month => "date_trunc('month', {$local})",
        };

        $rows = Order::query()
            ->toBase()
            ->where('status', OrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [
                BusinessCalendar::dayStart($report->from, $report->timezone),
                BusinessCalendar::dayEnd($report->to, $report->timezone),
            ])
            ->selectRaw("to_char({$bucket}, 'YYYY-MM-DD') as period", [$report->timezone])
            ->selectRaw('count(*) as orders')
            ->selectRaw('sum(order_total) as total')
            ->groupBy('period')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            /** @var object{period: string, orders: int|string, total: string} $row */
            $sums[$row->period] = [(int) $row->orders, Money::add('0.00', $row->total)];
        }

        return $sums;
    }

    /**
     * The first day of every bucket that overlaps the range.
     *
     * @return list<string>
     */
    private function periods(SalesReportData $report): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $report->from, 'UTC');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $report->to, 'UTC');

        if (! $day instanceof CarbonImmutable || ! $last instanceof CarbonImmutable) {
            return [];
        }

        $period = match ($report->group) {
            SalesGrouping::Day => $day,
            SalesGrouping::Week => $day->subDays(($day->dayOfWeek + 1) % 7),
            SalesGrouping::Month => $day->startOfMonth(),
        };
        $periods = [];

        while ($period->lessThanOrEqualTo($last)) {
            $periods[] = $period->toDateString();
            $period = match ($report->group) {
                SalesGrouping::Day => $period->addDay(),
                SalesGrouping::Week => $period->addWeek(),
                SalesGrouping::Month => $period->addMonthNoOverflow(),
            };
        }

        return $periods;
    }
}
