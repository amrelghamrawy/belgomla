<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Reports\Data\SalesReportData;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * GET /api/dashboard/reports/sales (D5 a, BR-R05): per bucket, delivered orders counted and summed by `delivered_at`
 * (gross), live returns summed by `returned_at` (returns), and net = gross − returns, in business days (D60). Weeks
 * start on Saturday (D58): Postgres truncates to ISO Monday weeks, so the day is shifted by two before truncating and
 * back after. Every bucket of the range is listed, empty ones as zero.
 */
final readonly class SalesQuery
{
    /**
     * @return array{buckets: list<array{period_start: string, orders: int, gross: string, returns: string, net: string}>, orders: int, gross: string, returns: string, net: string}
     */
    public function get(SalesReportData $report): array
    {
        $sales = $this->sales($report);
        $returns = $this->returns($report);
        $buckets = [];
        $orders = 0;
        $gross = '0.00';
        $returned = '0.00';

        foreach ($report->group->periods($report->from, $report->to) as $period) {
            [$count, $sum] = $sales[$period] ?? [0, '0.00'];
            $back = $returns[$period] ?? '0.00';
            $buckets[] = ['period_start' => $period, 'orders' => $count, 'gross' => $sum, 'returns' => $back, 'net' => Money::subtract($sum, $back)];
            $orders += $count;
            $gross = Money::add($gross, $sum);
            $returned = Money::add($returned, $back);
        }

        return ['buckets' => $buckets, 'orders' => $orders, 'gross' => $gross, 'returns' => $returned, 'net' => Money::subtract($gross, $returned)];
    }

    /**
     * @return array<string, array{0: int, 1: string}> period start (Y-m-d) => [orders, total]
     */
    private function sales(SalesReportData $report): array
    {
        $rows = $this->bucketed(Order::query()->where('status', OrderStatus::Delivered->value), 'orders.delivered_at', $report)
            ->selectRaw('count(*) as orders')
            ->selectRaw('sum(order_total) as total')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            /** @var object{period: string, orders: int|string, total: string} $row */
            $sums[$row->period] = [(int) $row->orders, Money::add('0.00', $row->total)];
        }

        return $sums;
    }

    /**
     * @return array<string, string> period start (Y-m-d) => returned total
     */
    private function returns(SalesReportData $report): array
    {
        $rows = $this->bucketed(OrderReturn::query()->whereNull('voided_at'), 'order_returns.returned_at', $report)
            ->selectRaw('sum(total) as total')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            /** @var object{period: string, total: string} $row */
            $sums[$row->period] = Money::add('0.00', $row->total);
        }

        return $sums;
    }

    /**
     * Rows of the range grouped by the bucket of the given timestamp column, as `period`.
     *
     * @template TModel of Order|OrderReturn
     *
     * @param  Builder<TModel>  $query
     */
    private function bucketed(Builder $query, string $column, SalesReportData $report): QueryBuilder
    {
        $bucket = $report->group->bucket("({$column} AT TIME ZONE 'UTC') AT TIME ZONE ?");

        return $query->toBase()
            ->whereBetween($column, [
                BusinessCalendar::dayStart($report->from, $report->timezone),
                BusinessCalendar::dayEnd($report->to, $report->timezone),
            ])
            ->selectRaw("to_char({$bucket}, 'YYYY-MM-DD') as period", [$report->timezone])
            ->groupBy('period');
    }
}
