<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Expenses\Queries\ExpenseTotalsQuery;
use App\Modules\Inventory\Queries\AdjustmentLossesQuery;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Services\ReportCache;
use App\Support\BusinessCalendar;
use App\Support\Money;

/**
 * GET /api/dashboard/reports/profit-and-loss (V2 Task 19, D100): for the range (Cairo days, D60) and the range of the
 * same length just before:
 *
 * net sales (the sales analytics net, D94) − cost of goods (D98) = gross profit; − stock losses (the adjustments at
 * their frozen cost, by reason, D100 g) − expenses (by category, live, by `spent_on`) = net profit; net margin = net
 * profit ÷ net sales in %. The gross profit is null while a line went out without a cost, the losses null while an
 * adjustment has none, and the net null when either is; the expenses are always shown. Only the range applies: the
 * category and customer group filters are ignored (D100 j). Cached like the other reports (D94 b, D100 k).
 *
 * @phpstan-type Figures array{from: string, to: string, net_sales: string, cost_of_goods: string, gross_profit: string|null, stock_losses: string|null, losses_by_reason: array<string, string>, expenses: string, expenses_by_category: array<string, string>, net_profit: string|null, net_margin: string|null, uncosted_lines: int, uncosted_adjustments: int}
 * @phpstan-type ProfitAndLoss array{current: Figures, previous: Figures}
 */
final readonly class ProfitAndLossQuery
{
    public function __construct(
        private SalesAnalyticsQuery $sales,
        private AdjustmentLossesQuery $losses,
        private ExpenseTotalsQuery $expenses,
        private ReportCache $cache,
    ) {}

    /**
     * @return array{generated_at: string, data: ProfitAndLoss}
     */
    public function get(ReportFiltersData $filters): array
    {
        $range = new ReportFiltersData($filters->from, $filters->to, $filters->group, null, null, $filters->timezone);

        return $this->cache->remember(ReportName::ProfitAndLoss, ['from' => $range->from, 'to' => $range->to, 'timezone' => $range->timezone], function () use ($range): array {
            [$previousFrom, $previousTo] = $this->sales->previousRange($range);

            return [
                'current' => $this->figures($range, $range->from, $range->to),
                'previous' => $this->figures($range, $previousFrom, $previousTo),
            ];
        });
    }

    /**
     * @return Figures
     */
    public function figures(ReportFiltersData $range, string $from, string $to): array
    {
        $start = BusinessCalendar::dayStart($from, $range->timezone);
        $end = BusinessCalendar::dayEnd($to, $range->timezone);
        $netSales = $this->sales->period($range, $from, $to)['totals']['net'];
        $profit = $this->sales->profit($range, $start, $end, $netSales);
        $losses = $this->losses->between($start, $end);
        $byCategory = $this->expenses->byCategory($from, $to);
        $expenses = array_reduce($byCategory, Money::add(...), '0.00');
        $stockLosses = $losses['uncosted'] > 0 ? null : $losses['total'];
        $net = $profit['profit'] === null || $stockLosses === null ? null : Money::subtract(Money::subtract($profit['profit'], $stockLosses), $expenses);

        return [
            'from' => $from,
            'to' => $to,
            'net_sales' => $netSales,
            'cost_of_goods' => $profit['cost'],
            'gross_profit' => $profit['profit'],
            'stock_losses' => $stockLosses,
            'losses_by_reason' => $losses['by_reason'],
            'expenses' => $expenses,
            'expenses_by_category' => $byCategory,
            'net_profit' => $net,
            'net_margin' => $net === null ? null : Money::percentOf($net, $netSales),
            'uncosted_lines' => $profit['uncosted_lines'],
            'uncosted_adjustments' => $losses['uncosted'],
        ];
    }
}
