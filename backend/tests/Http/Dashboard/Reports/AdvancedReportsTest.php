<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Tenancy\Enums\TenantUserRole;
use Carbon\CarbonImmutable;

/*
| V2 Task 16: the five advanced report endpoints and their exports (08 §11). 2026-10-10 is a Saturday; Cairo is UTC+3
| (D60). One order delivered on 2026-10-09: 2 books at 100.00 (list 120.00), 1 pen at 50.00, order discount 25.00 →
| 225.00; 100.00 of it collected in cash the same day.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->owner = $this->actingAsOwner();
    $this->book = $this->reportSku('BK-1', onHand: 10);
    $this->pen = $this->reportSku('PN-1', CategoryKind::Stationery, '50.00', onHand: 4);
    $this->customer = $this->makeCustomer(['name' => 'مكتبة النور']);
    $this->order = $this->reportOrder([[$this->book, 2, '100.00', 'list' => '120.00'], [$this->pen, 1, '50.00']], '2026-10-09 10:00:00', $this->customer, '25.00', order: ['collected_amount' => '100.00', 'collection_status' => 'partially_collected']);
    $this->inTenant(fn () => CashCollection::factory()->create(['order_id' => $this->order->id, 'amount' => '100.00', 'collected_at' => '2026-10-09 11:00:00']));
});

test('the sales analytics answer the documented report', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/sales-analytics?from=2026-10-09&to=2026-10-10')
        ->assertOk()
        ->assertJsonPath('data.generated_at', '2026-10-10T12:00:00+00:00')
        ->assertJsonPath('data.totals', ['orders' => 1, 'gross' => '290.00', 'discounts' => '65.00', 'returns' => '0.00', 'net' => '225.00', 'average_order' => '225.00'])
        ->assertJsonPath('data.previous.from', '2026-10-07')
        ->assertJsonPath('data.by_section.0.kind', 'books');

    expectDocumentedShape($response->json('data'), 'SalesAnalyticsReport');
    expectDocumentedShape($response->json('data.totals'), 'SalesTotals');
});

test('the product report answers a page of documented rows with its range, order and age', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/products?from=2026-10-09&to=2026-10-10&sort=quantity')
        ->assertOk()
        ->assertJsonPath('data.0.sku_code', 'BK-1')
        ->assertJsonPath('data.0.revenue', '180.00')
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('from', '2026-10-09')
        ->assertJsonPath('sort', 'quantity')
        ->assertJsonPath('generated_at', '2026-10-10T12:00:00+00:00');

    expectDocumentedShape($response->json('data.0'), 'ProductPerformanceRow');
});

test('the customer report answers a page of documented rows with its view and threshold', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/customers?from=2026-10-09&to=2026-10-10')
        ->assertOk()
        ->assertJsonPath('data.0.customer.name', 'مكتبة النور')
        ->assertJsonPath('data.0.revenue', '225.00')
        ->assertJsonPath('data.0.outstanding', '125.00')
        ->assertJsonPath('view', 'top')
        ->assertJsonPath('dormant_days', 45);

    expectDocumentedShape($response->json('data.0'), 'CustomerPatternRow');
});

test('the inventory report answers a page of documented rows with the summary', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/inventory?from=2026-10-09&to=2026-10-10&dead_days=30')
        ->assertOk()
        ->assertJsonPath('data.0.sku_code', 'BK-1')
        ->assertJsonPath('data.0.stock_value', '1000.00')
        ->assertJsonPath('summary.stock_value', '1200.00')
        ->assertJsonPath('dead_days', 30);

    expectDocumentedShape($response->json('data.0'), 'InventoryAnalyticsRow');
    expectDocumentedShape($response->json('summary'), 'InventorySummary');
});

test('the collections report answers the documented report', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/collections?from=2026-10-09&to=2026-10-10')
        ->assertOk()
        ->assertJsonPath('data.totals', ['cash' => '100.00', 'credit' => '0.00', 'refunds' => '0.00', 'net' => '100.00', 'delivered' => '225.00', 'collection_rate' => '44.44'])
        ->assertJsonPath('data.aging.outstanding', '125.00');

    expectDocumentedShape($response->json('data'), 'CollectionsReport');
    expectDocumentedShape($response->json('data.aging'), 'ReportAging');
});

test('the advanced reports default to the last 30 days by day', function (string $report): void {
    $this->tenantJson('GET', "/api/dashboard/reports/{$report}")
        ->assertOk()
        ->assertJsonPath(in_array($report, ['sales-analytics', 'collections'], true) ? 'data.from' : 'from', '2026-09-11');
})->with(['sales-analytics', 'products', 'customers', 'inventory', 'collections']);

test('every report filter is validated', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/reports/products?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'from not a day' => ['from=2026/10/01', 'from'],
    'from too early' => ['from=1999-12-31', 'from'],
    'to before from' => ['from=2026-10-05&to=2026-10-04', 'to'],
    'unknown grouping' => ['group=year', 'group'],
    'category not an id' => ['category_id=abc', 'category_id'],
    'customer group below 1' => ['customer_group_id=0', 'customer_group_id'],
    'unknown sort' => ['sort=price', 'sort'],
    'unknown view' => ['view=all', 'view'],
    'dormant days below 1' => ['dormant_days=0', 'dormant_days'],
    'dead days above ten years' => ['dead_days=3651', 'dead_days'],
    'dead only not a flag' => ['dead_only=maybe', 'dead_only'],
    'page below 1' => ['page=0', 'page'],
    'page size above 100' => ['per_page=101', 'per_page'],
    'unknown file format' => ['format=pdf', 'format'],
]);

test('BR-T03 a category or customer group of another store matches nothing', function (): void {
    $other = $this->otherTenant();
    $category = $this->inTenant(fn () => App\Modules\Catalog\Models\Category::factory()->create(), $other);

    $this->tenantJson('GET', "/api/dashboard/reports/products?from=2026-10-09&category_id={$category->id}")
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('BR-T03 no advanced report counts another store', function (): void {
    $other = $this->otherTenant();
    $this->reportOrder([[$this->reportSku('BK-9', tenant: $other), 9, '100.00']], '2026-10-09 10:00:00', $this->makeCustomer(tenant: $other), tenant: $other);

    $this->tenantJson('GET', '/api/dashboard/reports/sales-analytics?from=2026-10-09')->assertJsonPath('data.totals.orders', 1);
    $this->tenantJson('GET', '/api/dashboard/reports/products?from=2026-10-09')->assertJsonPath('meta.total', 2);
    $this->tenantJson('GET', '/api/dashboard/reports/customers?from=2026-10-09')->assertJsonPath('meta.total', 1);
    $this->tenantJson('GET', '/api/dashboard/reports/inventory?from=2026-10-09')->assertJsonPath('meta.total', 2);
});

test('a guest is refused on every advanced report', function (string $uri): void {
    $this->app['auth']->forgetGuards();

    $this->tenantJson('GET', $uri)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
})->with([
    '/api/dashboard/reports/sales-analytics',
    '/api/dashboard/reports/products',
    '/api/dashboard/reports/customers',
    '/api/dashboard/reports/inventory',
    '/api/dashboard/reports/collections',
    '/api/dashboard/reports/products/export',
]);

test('D82 the accountant reads the reports, the sales role does not', function (TenantUserRole $role, int $status): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));

    $this->tenantJson('GET', '/api/dashboard/reports/sales-analytics')->assertStatus($status);
})->with([
    'accountant' => [TenantUserRole::Accountant, 200],
    'sales' => [TenantUserRole::Sales, 403],
    'warehouse' => [TenantUserRole::Warehouse, 403],
]);

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (string $uri): void {
    $this->tenantJson('GET', $uri, tenant: $this->otherTenant())->assertUnauthorized();
})->with(['/api/dashboard/reports/sales-analytics', '/api/dashboard/reports/inventory/export']);

test('D83 a report exports as an excel file with arabic headers, one row per line', function (string $report, string $firstHeader, int $rows): void {
    $response = $this->tenantJson('GET', "/api/dashboard/reports/{$report}/export?from=2026-10-09&to=2026-10-10")
        ->assertOk()
        ->assertHeader('Content-Type', SpreadsheetFormat::Xlsx->mimeType())
        ->assertDownload("report-{$report}-2026-10-09-2026-10-10.xlsx");

    $sheet = spreadsheetRows(downloadedFile($response));

    expect(array_values($sheet)[0][0])->toBe($firstHeader)
        ->and($sheet)->toHaveCount($rows + 1);
})->with([
    'sales: two days and the totals' => ['sales-analytics', 'الفترة', 3],
    'products: two skus' => ['products', 'كود الصنف', 2],
    'customers: one customer' => ['customers', 'العميل', 1],
    'inventory: two skus' => ['inventory', 'كود الصنف', 2],
    'collections: two days and the totals' => ['collections', 'الفترة', 3],
]);

test('D83 a report exports as a csv with a bom and the labels as header', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/products/export?format=csv&from=2026-10-09&sort=quantity')
        ->assertOk()
        ->assertHeader('Content-Type', SpreadsheetFormat::Csv->mimeType());

    $content = $response->streamedContent();
    $rows = spreadsheetRows(downloadedFile($response, SpreadsheetFormat::Csv), SpreadsheetFormat::Csv);

    expect(str_starts_with($content, "\u{FEFF}"))->toBeTrue()
        ->and(array_values($rows)[1])->toBe(['BK-1', 'منتج BK-1', '', 'لا', '2', '2', '0', '180', '0', '0', '0', '1', '2026-10-09']);
});

test('the export of an unknown report is not found', function (): void {
    $this->tenantJson('GET', '/api/dashboard/reports/profits/export')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});
