<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Data\ReportOptionsData;
use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 16 (D83): every advanced report as a file: Arabic labels, numbers as numbers, dates as Cairo days, نعم/لا.
| One order delivered on 2026-10-09 21:30 UTC (2026-10-10 in Cairo): 2 books at 100.00 and 1 pen at 50.00.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->book = $this->reportSku('BK-1', onHand: 10);
    $this->pen = $this->reportSku('PN-1', CategoryKind::Stationery, '50.00');
    $this->customer = $this->makeCustomer(['name' => 'مكتبة النور', 'phone' => '+201000000001']);
    $this->reportOrder([[$this->book, 2, '100.00'], [$this->pen, 1, '50.00']], '2026-10-09 21:30:00', $this->customer);

    $this->export = function (ReportName $report, SpreadsheetFormat $format = SpreadsheetFormat::Xlsx): array {
        $path = sys_get_temp_dir().'/report-'.Illuminate\Support\Str::uuid().'.'.$format->value;
        app(ReportExporter::class)->write($path, $report, new ReportFiltersData('2026-10-09', '2026-10-10', SalesGrouping::Day, null, null, 'Africa/Cairo'), new ReportOptionsData(ProductSort::Revenue, CustomerView::Top, 45, 60, false), $format);

        return array_values(spreadsheetRows($path, $format));
    };
});

test('D83 the sales sheet has a row per bucket and a totals row', function (): void {
    expect(($this->export)(ReportName::SalesAnalytics))->toBe([
        ['الفترة', 'الطلبات', 'الإجمالي قبل الخصم', 'الخصومات', 'المرتجعات', 'الصافي'],
        ['2026-10-09', '0', '0', '0', '0', '0'],
        ['2026-10-10', '1', '250', '0', '0', '250'],
        ['الإجمالي', '1', '250', '0', '0', '250'],
    ]);
});

test('D83 the product sheet has a row per sku, the last sale as a cairo day', function (): void {
    expect(($this->export)(ReportName::Products)[1])->toBe(['BK-1', 'منتج BK-1', '', 'لا', '2', '2', '0', '200', '0', '0', '0', '1', '2026-10-10']);
});

test('D83 the customer sheet has a row per customer', function (): void {
    expect(($this->export)(ReportName::Customers)[1])->toBe(['مكتبة النور', '+201000000001', '', '1', '250', '250', '2026-10-10', '2026-10-10', '', '0', 'لا', '250', '250']);
});

test('D83 D94 the inventory sheet has a row per sku with its value at the base price', function (): void {
    expect(($this->export)(ReportName::Inventory)[1])->toBe(['BK-1', 'منتج BK-1', '', '10', '10', '1000', '2', '10', '2026-10-10', 'لا', '0', '0', '0', '0']);
});

test('D83 the collections sheet has a row per bucket and a totals row', function (): void {
    expect(($this->export)(ReportName::Collections)[0])->toBe(['الفترة', 'نقدي', 'من رصيد العميل', 'مردود', 'صافي التحصيل', 'صافي المسلَّم'])
        ->and(($this->export)(ReportName::Collections))->toHaveCount(4);
});

test('a csv export has the labels as its header', function (): void {
    expect(($this->export)(ReportName::Products, SpreadsheetFormat::Csv)[0][0])->toBe('كود الصنف');
});

test('the file is named after the report, its range and format', function (): void {
    expect(app(ReportExporter::class)->fileName(ReportName::Customers, new ReportFiltersData('2026-10-01', '2026-10-31', SalesGrouping::Day, null, null, 'Africa/Cairo'), SpreadsheetFormat::Csv))
        ->toBe('report-customers-2026-10-01-2026-10-31.csv');
});
