<?php

declare(strict_types=1);

use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Enums\SalesGrouping;

/*
| V2 Task 16: the reports' enums and the bucketing of SalesGrouping (D58, D60).
*/

test('every advanced reports enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([ReportName::class, ProductSort::class, CustomerView::class]);

test('the advanced reports are sales, products, customers, inventory and collections', function (): void {
    expect(ReportName::toArray())->toBe(['sales-analytics' => 'المبيعات', 'products' => 'المنتجات', 'customers' => 'العملاء', 'inventory' => 'المخزون', 'collections' => 'التحصيل', 'profit-and-loss' => 'الأرباح والخسائر']);
});

test('a report export is named after the report and its range', function (): void {
    expect(ReportName::Inventory->fileName('2026-10-01', '2026-10-31'))->toBe('report-inventory-2026-10-01-2026-10-31');
});

test('products sort by revenue, quantity or return rate; customers show the top or the dormant', function (): void {
    expect(array_keys(ProductSort::toArray()))->toBe(['revenue', 'quantity', 'return_rate'])
        ->and(array_keys(CustomerView::toArray()))->toBe(['top', 'dormant']);
});

test('D58 the periods of a range start on the day, the saturday on or before, or the first of the month', function (SalesGrouping $group, string $from, string $to, array $periods): void {
    expect($group->periods($from, $to))->toBe($periods);
})->with([
    'days' => [SalesGrouping::Day, '2026-10-30', '2026-11-01', ['2026-10-30', '2026-10-31', '2026-11-01']],
    'weeks from a thursday' => [SalesGrouping::Week, '2026-10-01', '2026-10-10', ['2026-09-26', '2026-10-03', '2026-10-10']],
    'weeks from a saturday' => [SalesGrouping::Week, '2026-10-03', '2026-10-09', ['2026-10-03']],
    'months' => [SalesGrouping::Month, '2026-01-31', '2026-03-01', ['2026-01-01', '2026-02-01', '2026-03-01']],
]);

test('D58 the bucket expression truncates to the day, a saturday week or the month', function (SalesGrouping $group, string $sql): void {
    expect($group->bucket('t'))->toBe($sql);
})->with([
    'day' => [SalesGrouping::Day, "date_trunc('day', t)"],
    'week' => [SalesGrouping::Week, "date_trunc('week', t + interval '2 days') - interval '2 days'"],
    'month' => [SalesGrouping::Month, "date_trunc('month', t)"],
]);
