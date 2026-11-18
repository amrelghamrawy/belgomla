<?php

declare(strict_types=1);

use App\Modules\Reports\Http\Resources\CollectionsReportResource;
use App\Modules\Reports\Http\Resources\CustomerPatternResource;
use App\Modules\Reports\Http\Resources\InventoryAnalyticsResource;
use App\Modules\Reports\Http\Resources\ProductPerformanceResource;
use App\Modules\Reports\Http\Resources\SalesAnalyticsResource;

/*
| V2 Task 16: the advanced report resources render exactly their documented keys (08 §11).
*/

test('the sales analytics resource renders the range, age and every section', function (): void {
    $totals = ['orders' => 1, 'gross' => '10.00', 'discounts' => '0.00', 'returns' => '0.00', 'net' => '10.00', 'average_order' => '10.00'];
    $report = ['from' => '2026-10-01', 'to' => '2026-10-02', 'group' => 'day', 'buckets' => [], 'totals' => $totals, 'profit' => ['cost' => '4.00', 'profit' => '6.00', 'margin' => '60.00', 'uncosted_lines' => 0], 'previous' => ['from' => '2026-09-29', 'to' => '2026-09-30', 'totals' => $totals, 'buckets' => []], 'by_section' => [], 'by_customer_group' => []];

    expect(rendered(new SalesAnalyticsResource(['generated_at' => '2026-10-02T10:00:00+00:00', 'data' => $report]), dashboardRequest('/api/dashboard/reports/sales-analytics')))
        ->toBe(['from' => '2026-10-01', 'to' => '2026-10-02', 'group' => 'day', 'generated_at' => '2026-10-02T10:00:00+00:00', 'buckets' => [], 'totals' => $totals, 'profit' => $report['profit'], 'previous' => $report['previous'], 'by_section' => [], 'by_customer_group' => []]);
});

test('the collections report resource renders the range, age, buckets, totals and aging', function (): void {
    $aging = ['current' => '0.00', '0_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', 'over_90' => '0.00', 'overdue' => '0.00', 'outstanding' => '0.00'];
    $totals = ['cash' => '0.00', 'credit' => '0.00', 'refunds' => '0.00', 'net' => '0.00', 'delivered' => '0.00', 'collection_rate' => null];
    $report = ['from' => '2026-10-01', 'to' => '2026-10-02', 'group' => 'week', 'buckets' => [], 'totals' => $totals, 'by_method' => [], 'aging' => $aging];

    expect(array_keys(rendered(new CollectionsReportResource(['generated_at' => '2026-10-02T10:00:00+00:00', 'data' => $report]), dashboardRequest('/api/dashboard/reports/collections'))))
        ->toBe(['from', 'to', 'group', 'generated_at', 'buckets', 'totals', 'by_method', 'aging']);
});

test('a product performance row renders its documented keys', function (): void {
    $row = ['sku_id' => 1, 'product_id' => 2, 'product_name' => 'علوم', 'sku_code' => 'BK-1', 'sku_name' => null, 'is_bundle' => false, 'quantity' => 3, 'quantity_base' => 5, 'bundle_quantity_base' => 2, 'revenue' => '181.00', 'returns' => '81.00', 'returned_base' => 1, 'return_rate' => '20.00', 'customers' => 2, 'last_sold_at' => null, 'cost' => '80.00', 'profit' => '101.00', 'margin' => '55.80', 'uncosted_lines' => 0];

    expect(rendered(new ProductPerformanceResource($row), dashboardRequest('/api/dashboard/reports/products')))->toBe($row);
});

test('a customer pattern row renders its documented keys', function (): void {
    $row = ['customer' => ['id' => 1, 'name' => 'أ', 'phone' => '+201000000001'], 'customer_group' => null, 'orders' => 1, 'revenue' => '10.00', 'average_order' => '10.00', 'first_order_at' => '2026-09-01T10:00:00+00:00', 'last_order_at' => '2026-10-01T10:00:00+00:00', 'lifetime_orders' => 2, 'average_interval_days' => 30.5, 'days_since_last_order' => 9, 'dormant' => false, 'outstanding' => '0.00', 'overdue' => '0.00'];

    expect(rendered(new CustomerPatternResource($row), dashboardRequest('/api/dashboard/reports/customers')))->toBe($row);
});

test('an inventory analytics row renders its documented keys', function (): void {
    $row = ['sku_id' => 1, 'product_id' => 2, 'product_name' => 'علوم', 'sku_code' => 'BK-1', 'sku_name' => null, 'on_hand' => 10, 'available' => 8, 'stock_value' => '1000.00', 'sold_base' => 0, 'days_of_cover' => null, 'last_sold_at' => null, 'dead' => true, 'movements' => ['opening' => 10, 'adjustment' => 0, 'sale' => 0, 'return' => 0, 'receive' => 0]];

    expect(rendered(new InventoryAnalyticsResource($row), dashboardRequest('/api/dashboard/reports/inventory')))->toBe($row);
});
