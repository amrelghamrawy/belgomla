<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Services\Spreadsheet;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Data\ReportOptionsData;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Queries\CollectionsReportQuery;
use App\Modules\Reports\Queries\CustomerPatternsQuery;
use App\Modules\Reports\Queries\InventoryAnalyticsQuery;
use App\Modules\Reports\Queries\ProductPerformanceQuery;
use App\Modules\Reports\Queries\ProfitAndLossQuery;
use App\Modules\Reports\Queries\SalesAnalyticsQuery;
use Carbon\CarbonImmutable;
use Generator;

/**
 * GET /api/dashboard/reports/{name}/export (V2 Task 16, D83): an advanced report as an XLSX (Arabic labels, a
 * right-to-left sheet) or CSV (UTF-8 with a BOM, keys only) file through Catalog's Spreadsheet, the only OpenSpout user.
 * Bucketed reports (sales, collections) write one row per bucket and a totals row; listed reports (products,
 * customers, inventory) write every row, not one page. Money and rates are numbers, dates Cairo days (D60), flags نعم/لا.
 *
 * @phpstan-type Cell string|int|float|bool|null
 */
final readonly class ReportExporter
{
    public function __construct(
        private Spreadsheet $spreadsheet,
        private SalesAnalyticsQuery $sales,
        private ProductPerformanceQuery $products,
        private CustomerPatternsQuery $customers,
        private InventoryAnalyticsQuery $inventory,
        private CollectionsReportQuery $collections,
        private ProfitAndLossQuery $profitAndLoss,
    ) {}

    public function write(string $path, ReportName $report, ReportFiltersData $filters, ReportOptionsData $options, SpreadsheetFormat $format): void
    {
        [$headers, $rows] = match ($report) {
            ReportName::SalesAnalytics => $this->salesSheet($filters),
            ReportName::Products => $this->productsSheet($filters, $options),
            ReportName::Customers => $this->customersSheet($filters, $options),
            ReportName::Inventory => $this->inventorySheet($filters, $options),
            ReportName::Collections => $this->collectionsSheet($filters),
            ReportName::ProfitAndLoss => $this->profitAndLossSheet($filters),
        };

        $this->spreadsheet->write($path, $format, $headers, $rows, $report->label());
    }

    public function fileName(ReportName $report, ReportFiltersData $filters, SpreadsheetFormat $format): string
    {
        return $report->fileName($filters->from, $filters->to).'.'.$format->value;
    }

    /**
     * D100: one row per line of the statement, top to bottom, for the range and the one before (an unknown figure is
     * empty).
     *
     * @return array{0: array<string, string>, 1: iterable<array<string, Cell>>}
     */
    private function profitAndLossSheet(ReportFiltersData $filters): array
    {
        $report = $this->profitAndLoss->get($filters)['data'];
        $current = $report['current'];
        $previous = $report['previous'];
        $number = fn (?string $amount): ?float => $amount === null ? null : (float) $amount;
        $lines = [
            ['net_sales', 'صافي المبيعات'],
            ['cost_of_goods', 'تكلفة البضاعة المباعة'],
            ['gross_profit', 'مجمل الربح'],
            ['stock_losses', 'خسائر المخزون'],
            ['expenses', 'المصروفات'],
            ['net_profit', 'صافي الربح'],
            ['net_margin', 'هامش صافي الربح %'],
        ];
        $rows = [];

        foreach ($lines as [$key, $label]) {
            $rows[] = ['line' => $label, 'current' => $number($current[$key]), 'previous' => $number($previous[$key])];
        }

        return [['line' => 'البند', 'current' => "{$current['from']} — {$current['to']}", 'previous' => "{$previous['from']} — {$previous['to']}"], $rows];
    }

    /**
     * @return array{0: array<string, string>, 1: iterable<array<string, Cell>>}
     */
    private function salesSheet(ReportFiltersData $filters): array
    {
        $report = $this->sales->get($filters)['data'];
        $rows = [];

        foreach ([...$report['buckets'], ['period_start' => 'الإجمالي', ...$report['totals']]] as $bucket) {
            $rows[] = [
                'period_start' => $bucket['period_start'],
                'orders' => $bucket['orders'],
                'gross' => (float) $bucket['gross'],
                'discounts' => (float) $bucket['discounts'],
                'returns' => (float) $bucket['returns'],
                'net' => (float) $bucket['net'],
            ];
        }

        return [['period_start' => 'الفترة', 'orders' => 'الطلبات', 'gross' => 'الإجمالي قبل الخصم', 'discounts' => 'الخصومات', 'returns' => 'المرتجعات', 'net' => 'الصافي'], $rows];
    }

    /**
     * @return array{0: array<string, string>, 1: Generator<int, array<string, Cell>>}
     */
    private function productsSheet(ReportFiltersData $filters, ReportOptionsData $options): array
    {
        $headers = [
            'sku_code' => 'كود الصنف', 'product_name' => 'المنتج', 'sku_name' => 'الصنف', 'is_bundle' => 'باقة', 'quantity' => 'الكمية',
            'quantity_base' => 'الكمية بالوحدة الأساسية', 'bundle_quantity_base' => 'منها داخل باقات', 'revenue' => 'صافي الإيراد', 'returns' => 'المرتجعات',
            'returned_base' => 'الكمية المرتجعة', 'return_rate' => 'نسبة المرتجع %', 'customers' => 'عدد العملاء', 'last_sold_at' => 'آخر بيع',
            'cost' => 'التكلفة', 'profit' => 'الربح', 'margin' => 'هامش الربح %', 'uncosted_lines' => 'سطور بدون تكلفة',
        ];

        return [$headers, (function () use ($filters, $options): Generator {
            foreach ($this->products->rows($filters, $options->sort) as $row) {
                yield [
                    ...$row,
                    'is_bundle' => $this->yesNo($row['is_bundle']),
                    'revenue' => (float) $row['revenue'],
                    'returns' => (float) $row['returns'],
                    'return_rate' => $row['return_rate'] === null ? null : (float) $row['return_rate'],
                    'last_sold_at' => $this->day($row['last_sold_at'], $filters),
                    'cost' => (float) $row['cost'],
                    'profit' => $row['profit'] === null ? null : (float) $row['profit'],
                    'margin' => $row['margin'] === null ? null : (float) $row['margin'],
                ];
            }
        })()];
    }

    /**
     * @return array{0: array<string, string>, 1: Generator<int, array<string, Cell>>}
     */
    private function customersSheet(ReportFiltersData $filters, ReportOptionsData $options): array
    {
        $headers = [
            'name' => 'العميل', 'phone' => 'الهاتف', 'group' => 'المستوى', 'orders' => 'الطلبات', 'revenue' => 'صافي الإيراد', 'average_order' => 'متوسط الطلب',
            'first_order_at' => 'أول طلب', 'last_order_at' => 'آخر طلب', 'average_interval_days' => 'متوسط الأيام بين الطلبات', 'days_since_last_order' => 'أيام منذ آخر طلب',
            'dormant' => 'خامل', 'outstanding' => 'المستحق', 'overdue' => 'المتأخر',
        ];

        return [$headers, (function () use ($filters, $options): Generator {
            foreach ($this->customers->rows($filters, $options->view, $options->dormantDays) as $row) {
                yield [
                    'name' => $row['customer']['name'],
                    'phone' => $row['customer']['phone'],
                    'group' => $row['customer_group']['name'] ?? null,
                    'orders' => $row['orders'],
                    'revenue' => (float) $row['revenue'],
                    'average_order' => $row['average_order'] === null ? null : (float) $row['average_order'],
                    'first_order_at' => $this->day($row['first_order_at'], $filters),
                    'last_order_at' => $this->day($row['last_order_at'], $filters),
                    'average_interval_days' => $row['average_interval_days'],
                    'days_since_last_order' => $row['days_since_last_order'],
                    'dormant' => $this->yesNo($row['dormant']),
                    'outstanding' => (float) $row['outstanding'],
                    'overdue' => (float) $row['overdue'],
                ];
            }
        })()];
    }

    /**
     * @return array{0: array<string, string>, 1: Generator<int, array<string, Cell>>}
     */
    private function inventorySheet(ReportFiltersData $filters, ReportOptionsData $options): array
    {
        $headers = [
            'sku_code' => 'كود الصنف', 'product_name' => 'المنتج', 'sku_name' => 'الصنف', 'on_hand' => 'الرصيد', 'available' => 'المتاح',
            'stock_value' => 'قيمة المخزون بسعر البيع', 'sold_base' => 'المباع في الفترة', 'days_of_cover' => 'أيام التغطية', 'last_sold_at' => 'آخر بيع', 'dead' => 'راكد',
            'opening' => 'رصيد افتتاحي', 'adjustment' => 'تسويات', 'sale' => 'مبيعات', 'return' => 'مرتجعات',
        ];

        return [$headers, (function () use ($filters, $options): Generator {
            foreach ($this->inventory->rows($filters, $options->deadDays, $options->deadOnly) as $row) {
                yield [
                    'sku_code' => $row['sku_code'],
                    'product_name' => $row['product_name'],
                    'sku_name' => $row['sku_name'],
                    'on_hand' => $row['on_hand'],
                    'available' => $row['available'],
                    'stock_value' => (float) $row['stock_value'],
                    'sold_base' => $row['sold_base'],
                    'days_of_cover' => $row['days_of_cover'],
                    'last_sold_at' => $this->day($row['last_sold_at'], $filters),
                    'dead' => $this->yesNo($row['dead']),
                    ...$row['movements'],
                ];
            }
        })()];
    }

    /**
     * @return array{0: array<string, string>, 1: iterable<array<string, Cell>>}
     */
    private function collectionsSheet(ReportFiltersData $filters): array
    {
        $report = $this->collections->get($filters)['data'];
        $rows = [];

        foreach ([...$report['buckets'], ['period_start' => 'الإجمالي', ...$report['totals']]] as $bucket) {
            $rows[] = [
                'period_start' => $bucket['period_start'],
                'cash' => (float) $bucket['cash'],
                'credit' => (float) $bucket['credit'],
                'refunds' => (float) $bucket['refunds'],
                'net' => (float) $bucket['net'],
                'delivered' => (float) $bucket['delivered'],
            ];
        }

        return [['period_start' => 'الفترة', 'cash' => 'نقدي', 'credit' => 'من رصيد العميل', 'refunds' => 'مردود', 'net' => 'صافي التحصيل', 'delivered' => 'صافي المسلَّم'], $rows];
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'نعم' : 'لا';
    }

    /** An ISO instant as its Cairo day (D60). */
    private function day(?string $instant, ReportFiltersData $filters): ?string
    {
        return $instant === null ? null : CarbonImmutable::parse($instant)->setTimezone($filters->timezone)->toDateString();
    }
}
