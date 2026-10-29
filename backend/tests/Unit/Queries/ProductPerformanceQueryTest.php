<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\ProductPerformanceQuery;
use App\Modules\Reports\Queries\SalesAnalyticsQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 16 product performance on a hand-computed dataset (Cairo is UTC+3 in October, D60).
|
| Order A (customer X, group «جملة»), delivered 2026-10-08: 2 books at 90.00 (list 100.00) and 1 pen at 50.00, order
| discount 23.00 → book 180.00 − 18.00 = 162.00, pen 50.00 − 5.00 = 45.00. Order B (customer Y), 2026-10-09: 1 book at
| 100.00. Order C (customer Y), 2026-10-09 12:00: 2 bundles SET-1 at 150.00 (300.00), each 1 book and 2 pens. On
| 2026-10-10 one book of A comes back for 81.00.
|
| BK-1: 3 own pieces, 5 base units (2 inside bundles), 162 + 100 − 81 = 181.00, 1 of 5 returned = 20%, 2 customers.
| PN-1: 1 own piece, 5 base units (4 inside bundles), 45.00. SET-1 (a bundle): 2, 300.00.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->books = Category::factory()->kind(CategoryKind::Books)->create();
    $this->book = $this->reportSku('BK-1', category: $this->books);
    $this->pen = $this->reportSku('PN-1', CategoryKind::Stationery, '50.00');
    $this->set = Product::factory()->bundle()->withSku(['code' => 'SET-1', 'conversion_factor' => 1])->create(['name' => 'طقم'])->skus()->sole();
    $this->group = CustomerGroup::factory()->create(['name' => 'جملة']);
    $this->x = $this->makeCustomer(['customer_group_id' => $this->group->id]);
    $this->y = $this->makeCustomer();
    $this->orderA = $this->reportOrder([[$this->book, 2, '90.00', 'list' => '100.00'], [$this->pen, 1, '50.00']], '2026-10-08 10:00:00', $this->x, '23.00');
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-10-09 10:00:00', $this->y);
    $this->orderC = $this->bundleOrder($this->set, [$this->defaultUnitId($this->book) => 1, $this->defaultUnitId($this->pen) => 2], bundles: 2, price: '150.00', delivered: 2, deliveredAt: '2026-10-09 12:00:00', order: ['customer_id' => $this->y->id]);
    $this->reportReturn($this->orderA, [[$this->orderA->items()->where('sku_id', $this->book->id)->sole(), 1, '81.00']], '2026-10-10 09:00:00');

    $this->filters = fn (?int $category = null, ?int $customerGroup = null, string $from = '2026-10-08'): ReportFiltersData => new ReportFiltersData($from, '2026-10-10', SalesGrouping::Day, $category, $customerGroup, 'Africa/Cairo');
    $this->products = fn (ProductSort $sort = ProductSort::Revenue, int $page = 1, int $perPage = 20, ?ReportFiltersData $filters = null): array => app(ProductPerformanceQuery::class)
        ->paginate($filters ?? ($this->filters)(), $sort, $page, $perPage)['page']->items();
    $this->row = fn (string $code): ?array => collect(($this->products)())->firstWhere('sku_code', $code);
});

test('each sku sold carries its quantities, net revenue, returns, return rate, customers and last sale', function (): void {
    expect(($this->row)('BK-1'))->toBe([
        'sku_id' => $this->book->id,
        'product_id' => $this->book->product_id,
        'product_name' => 'منتج BK-1',
        'sku_code' => 'BK-1',
        'sku_name' => null,
        'is_bundle' => false,
        'quantity' => 3,
        'quantity_base' => 5,
        'bundle_quantity_base' => 2,
        'revenue' => '181.00',
        'returns' => '81.00',
        'returned_base' => 1,
        'return_rate' => '20.00',
        'customers' => 2,
        'last_sold_at' => '2026-10-09T12:00:00+00:00',
    ]);
});

test('D93 a bundle counts once at its own row and once per component, flagged as inside bundles', function (): void {
    expect(($this->row)('SET-1'))->toMatchArray(['is_bundle' => true, 'quantity' => 2, 'quantity_base' => 2, 'bundle_quantity_base' => 0, 'revenue' => '300.00', 'customers' => 1])
        ->and(($this->row)('PN-1'))->toMatchArray(['quantity' => 1, 'quantity_base' => 5, 'bundle_quantity_base' => 4, 'revenue' => '45.00', 'return_rate' => '0.00']);
});

test('D94 the revenues of the rows add up to the net sales of the range', function (): void {
    $net = app(SalesAnalyticsQuery::class)->get(($this->filters)())['data']['totals']['net'];

    expect(array_reduce(($this->products)(), fn (string $sum, array $row): string => bcadd($sum, $row['revenue'], 2), '0.00'))->toBe($net)->toBe('526.00');
});

test('the rows are sorted by revenue, base units or return rate, highest first, then by code', function (ProductSort $sort, array $codes): void {
    expect(array_column(($this->products)($sort), 'sku_code'))->toBe($codes);
})->with([
    'revenue' => [ProductSort::Revenue, ['SET-1', 'BK-1', 'PN-1']],
    'quantity' => [ProductSort::Quantity, ['BK-1', 'PN-1', 'SET-1']],
    'return rate' => [ProductSort::ReturnRate, ['BK-1', 'PN-1', 'SET-1']],
]);

test('D93 a returned component counts against its bundle\'s revenue and in its own return rate', function (): void {
    $this->reportReturn($this->orderC, [[$this->orderC->items()->where('sku_id', $this->pen->id)->sole(), 2, '20.00']], '2026-10-10 10:00:00');

    expect(($this->row)('SET-1'))->toMatchArray(['revenue' => '280.00', 'returns' => '20.00', 'returned_base' => 0])
        ->and(($this->row)('PN-1'))->toMatchArray(['revenue' => '45.00', 'returned_base' => 2, 'return_rate' => '40.00']);
});

test('a sku only returned in the range has a negative revenue and no return rate', function (): void {
    $old = $this->reportOrder([[$this->pen, 3, '50.00']], '2026-09-01 10:00:00', $this->y);
    $this->reportReturn($old, [[$old->items()->sole(), 1, '50.00']], '2026-10-10 11:00:00');

    expect(($this->row)('PN-1'))->toMatchArray(['returned_base' => 1, 'return_rate' => '20.00'])
        ->and(collect(($this->products)(filters: ($this->filters)(from: '2026-10-10')))->firstWhere('sku_code', 'PN-1'))
        ->toMatchArray(['quantity_base' => 0, 'revenue' => '-50.00', 'returned_base' => 1, 'return_rate' => null, 'last_sold_at' => null]);
});

test('the first page is the top of the list and the rest is paginated', function (): void {
    $page = app(ProductPerformanceQuery::class)->paginate(($this->filters)(), ProductSort::Revenue, 2, 2)['page'];

    expect($page->total())->toBe(3)
        ->and($page->currentPage())->toBe(2)
        ->and(array_column($page->items(), 'sku_code'))->toBe(['PN-1']);
});

test('BR-P06 a category filter keeps the skus of that category', function (): void {
    expect(array_column(($this->products)(filters: ($this->filters)($this->books->id)), 'sku_code'))->toBe(['BK-1'])
        ->and(($this->products)(filters: ($this->filters)(999_999)))->toBe([]);
});

test('BR-PR12 a customer group filter keeps the sales to that group\'s customers', function (): void {
    $rows = ($this->products)(filters: ($this->filters)(customerGroup: $this->group->id));

    expect(array_column($rows, 'revenue', 'sku_code'))->toBe(['BK-1' => '81.00', 'PN-1' => '45.00']);
});

test('the export reads every row in the same order', function (): void {
    expect(array_column(iterator_to_array(app(ProductPerformanceQuery::class)->rows(($this->filters)(), ProductSort::Revenue), false), 'sku_code'))->toBe(['SET-1', 'BK-1', 'PN-1']);
});

test('BR-T03 another tenant\'s sales never count', function (): void {
    $other = $this->otherTenant();
    $sku = $this->reportSku('BK-1', tenant: $other);
    $this->reportOrder([[$sku, 9, '100.00']], '2026-10-09 10:00:00', $this->makeCustomer(tenant: $other), tenant: $other);

    expect(($this->row)('BK-1'))->toMatchArray(['quantity' => 3, 'customers' => 2]);
});

test('the report runs a fixed number of queries whatever the number of skus', function (): void {
    $few = $this->queriesRun(fn () => ($this->products)());
    $this->reportOrder([[$this->reportSku('NB-1'), 1, '10.00'], [$this->reportSku('NB-2'), 1, '10.00']], '2026-10-09 18:00:00', $this->y);
    cache()->flush();

    expect($this->queriesRun(fn () => ($this->products)()))->toBe($few)->toBeLessThanOrEqual(4);
});
