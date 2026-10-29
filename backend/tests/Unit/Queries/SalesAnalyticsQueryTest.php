<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Orders\Models\Order;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\SalesAnalyticsQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 16 sales analytics on a hand-computed dataset. 2026-10-10 is a Saturday; Cairo is UTC+3 until the last
| Thursday of October 2026 and UTC+2 after (D60).
|
| Order A (a customer of the group «جملة»), delivered 2026-10-08: 2 books at 90.00 (list 100.00) and 1 pen at 50.00 =
| items 230.00, order discount 23.00 → 207.00 (gross 250.00, discounts 20.00 + 23.00). Order B (no group), delivered
| 2026-10-09: 1 book at 100.00. On 2026-10-10 one book of A comes back for 81.00 (90.00 less the 10% order discount).
| Order C, delivered 2026-10-06 (the previous period): 2 pens at 50.00.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->books = Category::factory()->kind(CategoryKind::Books)->create(['name' => 'كتب']);
    $this->book = $this->reportSku('BK-1', category: $this->books);
    $this->pen = $this->reportSku('PN-1', CategoryKind::Stationery, '50.00');
    $this->group = CustomerGroup::factory()->create(['name' => 'جملة']);
    $this->wholesaler = $this->makeCustomer(['customer_group_id' => $this->group->id]);
    $this->shop = $this->makeCustomer();
    $this->orderA = $this->reportOrder([[$this->book, 2, '90.00', 'list' => '100.00'], [$this->pen, 1, '50.00']], '2026-10-08 10:00:00', $this->wholesaler, '23.00');
    $this->orderB = $this->reportOrder([[$this->book, 1, '100.00']], '2026-10-09 10:00:00', $this->shop);
    $this->reportReturn($this->orderA, [[$this->orderA->items()->where('sku_id', $this->book->id)->sole(), 1, '81.00']], '2026-10-10 09:00:00');
    $this->reportOrder([[$this->pen, 2, '50.00']], '2026-10-06 10:00:00', $this->shop);

    $this->analytics = fn (string $from = '2026-10-08', string $to = '2026-10-10', SalesGrouping $group = SalesGrouping::Day, ?int $category = null, ?int $customerGroup = null): array => app(SalesAnalyticsQuery::class)
        ->get(new ReportFiltersData($from, $to, $group, $category, $customerGroup, 'Africa/Cairo'))['data'];
});

test('BR-R05 BR-PR08 net is gross less the discounts less the returns, per business day', function (): void {
    expect(($this->analytics)()['buckets'])->toBe([
        ['period_start' => '2026-10-08', 'orders' => 1, 'gross' => '250.00', 'discounts' => '43.00', 'returns' => '0.00', 'net' => '207.00'],
        ['period_start' => '2026-10-09', 'orders' => 1, 'gross' => '100.00', 'discounts' => '0.00', 'returns' => '0.00', 'net' => '100.00'],
        ['period_start' => '2026-10-10', 'orders' => 0, 'gross' => '0.00', 'discounts' => '0.00', 'returns' => '81.00', 'net' => '-81.00'],
    ]);
});

test('the totals carry the average order, the delivered totals over the orders', function (): void {
    expect(($this->analytics)()['totals'])->toBe(['orders' => 2, 'gross' => '350.00', 'discounts' => '43.00', 'returns' => '81.00', 'net' => '226.00', 'average_order' => '153.50']);
});

test('BR-R05 a return counts on the day it came back, not on the delivery day', function (): void {
    $report = ($this->analytics)('2026-10-08', '2026-10-09');

    expect($report['totals']['returns'])->toBe('0.00')
        ->and($report['totals']['net'])->toBe('307.00');
});

test('a voided return takes nothing off', function (): void {
    $this->reportReturn($this->orderB, [[$this->orderB->items()->sole(), 1, '100.00']], '2026-10-10 10:00:00', voided: true);

    expect(($this->analytics)()['totals']['returns'])->toBe('81.00');
});

test('D94 the net sales of a range reconcile with the delivered order totals less the returns of the range', function (): void {
    $delivered = Order::query()->where('status', 'delivered')->whereBetween('delivered_at', ['2026-10-07 21:00:00', '2026-10-10 20:59:59'])->sum('order_total');

    expect(($this->analytics)()['totals']['net'])->toBe(bcsub((string) $delivered, '81.00', 2));
});

test('the period of the same length just before is given for comparison', function (): void {
    expect(($this->analytics)()['previous'])->toBe([
        'from' => '2026-10-05',
        'to' => '2026-10-07',
        'totals' => ['orders' => 1, 'gross' => '100.00', 'discounts' => '0.00', 'returns' => '0.00', 'net' => '100.00', 'average_order' => '100.00'],
        'buckets' => [
            ['period_start' => '2026-10-05', 'net' => '0.00'],
            ['period_start' => '2026-10-06', 'net' => '100.00'],
            ['period_start' => '2026-10-07', 'net' => '0.00'],
        ],
    ]);
});

test('D68 the sales are split by section, the order discount shared over the lines', function (): void {
    expect(($this->analytics)()['by_section'])->toBe([
        ['kind' => 'books', 'label' => 'كتب خارجية', 'orders' => 2, 'gross' => '300.00', 'discounts' => '38.00', 'returns' => '81.00', 'net' => '181.00'],
        ['kind' => 'stationery', 'label' => 'أدوات دراسية', 'orders' => 1, 'gross' => '50.00', 'discounts' => '5.00', 'returns' => '0.00', 'net' => '45.00'],
    ]);
});

test('BR-PR12 the sales are split by the customers\' group, no group last when it sold less', function (): void {
    expect(($this->analytics)()['by_customer_group'])->toBe([
        ['customer_group_id' => $this->group->id, 'name' => 'جملة', 'orders' => 1, 'gross' => '250.00', 'discounts' => '43.00', 'returns' => '81.00', 'net' => '126.00'],
        ['customer_group_id' => null, 'name' => null, 'orders' => 1, 'gross' => '100.00', 'discounts' => '0.00', 'returns' => '0.00', 'net' => '100.00'],
    ]);
});

test('a customer group filter keeps that group\'s customers only', function (): void {
    expect(($this->analytics)(customerGroup: $this->group->id)['totals'])->toBe(['orders' => 1, 'gross' => '250.00', 'discounts' => '43.00', 'returns' => '81.00', 'net' => '126.00', 'average_order' => '207.00']);
});

test('BR-P06 a category filter keeps the lines of that category and its sub-categories', function (): void {
    $child = Category::factory()->create(['parent_id' => $this->books->id, 'path' => $this->books->path.'99/', 'kind' => CategoryKind::Books]);
    $novel = $this->reportSku('BK-2', category: $child);
    $this->reportOrder([[$novel, 1, '40.00'], [$this->pen, 1, '50.00']], '2026-10-09 11:00:00', $this->shop);

    expect(($this->analytics)(category: $this->books->id)['totals'])->toBe(['orders' => 3, 'gross' => '340.00', 'discounts' => '38.00', 'returns' => '81.00', 'net' => '221.00', 'average_order' => '100.67']);
});

test('an unknown category matches nothing', function (): void {
    expect(($this->analytics)(category: 999_999)['totals']['orders'])->toBe(0);
});

test('D93 a bundle counts once, at the bundle line: its component lines add nothing', function (): void {
    $set = $this->reportSku('SET-1', CategoryKind::Books, '300.00');
    $order = $this->bundleOrder($set, [$this->defaultUnitId($this->book) => 1, $this->defaultUnitId($this->pen) => 2], bundles: 2, price: '150.00', delivered: 2, deliveredAt: '2026-10-09 12:00:00');

    expect(($this->analytics)('2026-10-09', '2026-10-09')['totals'])->toBe(['orders' => 2, 'gross' => '400.00', 'discounts' => '0.00', 'returns' => '0.00', 'net' => '400.00', 'average_order' => '200.00'])
        ->and($order->order_total)->toBe('300.00');
});

test('D93 a returned component lowers the bundle\'s section', function (): void {
    $set = $this->reportSku('SET-1', CategoryKind::Toys, '300.00');
    $order = $this->bundleOrder($set, [$this->defaultUnitId($this->book) => 1], bundles: 1, price: '150.00', delivered: 1, deliveredAt: '2026-10-09 12:00:00');
    $this->reportReturn($order, [[$order->items()->whereNotNull('parent_item_id')->sole(), 1, '150.00']], '2026-10-10 10:00:00');

    $toys = collect(($this->analytics)()['by_section'])->firstWhere('kind', 'toys');

    expect($toys)->toBe(['kind' => 'toys', 'label' => 'ألعاب', 'orders' => 1, 'gross' => '150.00', 'discounts' => '0.00', 'returns' => '150.00', 'net' => '0.00']);
});

test('BR-O12 BR-O13 a removed line is left out and a line counts what went out', function (): void {
    $this->reportOrder([[$this->book, 3, '100.00', 'delivered' => 1], [$this->pen, 4, '50.00', 'removed' => true]], '2026-10-09 15:00:00', $this->shop);

    expect(($this->analytics)('2026-10-09', '2026-10-09')['totals']['gross'])->toBe('200.00');
});

test('D88 cancelled and undelivered orders and pre-orders do not count', function (array $order): void {
    $this->reportOrder([[$this->book, 5, '100.00']], '2026-10-09 15:00:00', $this->shop, order: $order);

    expect(($this->analytics)('2026-10-09', '2026-10-09')['totals']['orders'])->toBe(1);
})->with([
    'cancelled' => [['status' => 'cancelled']],
    'out for delivery' => [['status' => 'out_for_delivery']],
    'a pre-order' => [['status' => 'pending', 'kind' => 'preorder']],
]);

test('D60 an order delivered at 21:59 UTC in november counts on that cairo day, at 22:00 UTC on the next', function (): void {
    $this->reportOrder([[$this->book, 1, '10.00']], '2026-11-15 21:59:59', $this->shop);
    $this->reportOrder([[$this->book, 1, '20.00']], '2026-11-15 22:00:00', $this->shop);

    $buckets = ($this->analytics)('2026-11-15', '2026-11-16')['buckets'];

    expect(array_column($buckets, 'gross', 'period_start'))->toBe(['2026-11-15' => '10.00', '2026-11-16' => '20.00']);
});

test('D58 the buckets are saturday weeks', function (): void {
    $buckets = ($this->analytics)('2026-10-01', '2026-10-10', SalesGrouping::Week)['buckets'];

    expect(array_column($buckets, 'net', 'period_start'))->toBe(['2026-09-26' => '0.00', '2026-10-03' => '407.00', '2026-10-10' => '-81.00']);
});

test('BR-T03 another tenant\'s sales never count', function (): void {
    $other = $this->otherTenant();
    $sku = $this->reportSku('BK-9', tenant: $other);
    $this->reportOrder([[$sku, 9, '100.00']], '2026-10-09 10:00:00', $this->makeCustomer(tenant: $other), tenant: $other);

    expect(($this->analytics)()['totals']['orders'])->toBe(2);
});

test('D94 the report is cached for ten minutes per filter set, with the moment it was computed', function (): void {
    $first = app(SalesAnalyticsQuery::class)->get(new ReportFiltersData('2026-10-08', '2026-10-10', SalesGrouping::Day, null, null, 'Africa/Cairo'));
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-10-09 18:00:00', $this->shop);
    $this->travel(9)->minutes();
    $cached = app(SalesAnalyticsQuery::class)->get(new ReportFiltersData('2026-10-08', '2026-10-10', SalesGrouping::Day, null, null, 'Africa/Cairo'));
    $this->travel(2)->minutes();
    $fresh = app(SalesAnalyticsQuery::class)->get(new ReportFiltersData('2026-10-08', '2026-10-10', SalesGrouping::Day, null, null, 'Africa/Cairo'));

    expect($first['generated_at'])->toBe('2026-10-10T12:00:00+00:00')
        ->and($cached)->toBe($first)
        ->and($fresh['data']['totals']['orders'])->toBe(3)
        ->and($fresh['generated_at'])->toBe('2026-10-10T12:11:00+00:00');
});

test('D94 another filter set is computed apart', function (): void {
    ($this->analytics)();

    expect(($this->analytics)(group: SalesGrouping::Month)['buckets'])->toHaveCount(1);
});

test('the report runs a fixed number of queries whatever the number of orders', function (): void {
    $few = $this->queriesRun(fn () => ($this->analytics)());
    $this->reportOrder([[$this->book, 1, '100.00'], [$this->pen, 1, '50.00']], '2026-10-09 18:00:00', $this->shop);
    $this->reportOrder([[$this->book, 2, '100.00']], '2026-10-08 18:00:00', $this->wholesaler);
    cache()->flush();

    expect($this->queriesRun(fn () => ($this->analytics)()))->toBe($few)->toBeLessThanOrEqual(12);
});
