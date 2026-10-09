<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Reports\Data\SalesReportData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\LowStockReportQuery;
use App\Modules\Reports\Queries\OutstandingReportQuery;
use App\Modules\Reports\Queries\OverviewQuery;
use App\Modules\Reports\Queries\SalesQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| Reports with seeded data and frozen time. 2026-10-10 is a Saturday; Cairo is UTC+3 in October (D60).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();

    $this->delivered = fn (string $at, string $total = '100.00', string $collected = '0.00', ?int $customerId = null): Order => Order::factory()->delivered()->create([
        'customer_id' => $customerId ?? $this->customer->id,
        'items_total' => $total,
        'order_total' => $total,
        'collected_amount' => $collected,
        'collection_status' => CollectionStatus::for($collected, $total),
        'delivered_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
    $this->sales = fn (string $from, string $to, SalesGrouping $group): array => app(SalesQuery::class)->get(new SalesReportData($from, $to, $group, 'Africa/Cairo'));
});

test('D5 the sales report sums delivered orders per business day, empty days as zero', function (): void {
    ($this->delivered)('2026-10-08 10:00:00', '150.00');
    ($this->delivered)('2026-10-08 18:00:00', '50.25');
    ($this->delivered)('2026-10-10 09:00:00', '100.00');
    Order::factory()->confirmed()->create(['items_total' => '999.00', 'order_total' => '999.00']);

    expect(($this->sales)('2026-10-08', '2026-10-10', SalesGrouping::Day))->toBe([
        'buckets' => [
            ['period_start' => '2026-10-08', 'orders' => 2, 'total' => '200.25'],
            ['period_start' => '2026-10-09', 'orders' => 0, 'total' => '0.00'],
            ['period_start' => '2026-10-10', 'orders' => 1, 'total' => '100.00'],
        ],
        'orders' => 3,
        'total' => '300.25',
    ]);
});

test('D60 an order delivered after midnight in cairo counts on the next day', function (): void {
    ($this->delivered)('2026-10-08 21:30:00');

    expect(array_column(($this->sales)('2026-10-08', '2026-10-09', SalesGrouping::Day)['buckets'], 'orders'))->toBe([0, 1]);
});

test('D60 the range is whole business days', function (): void {
    ($this->delivered)('2026-10-07 20:59:59');
    ($this->delivered)('2026-10-07 21:00:00');
    ($this->delivered)('2026-10-08 20:59:59');
    ($this->delivered)('2026-10-08 21:00:00');

    expect(($this->sales)('2026-10-08', '2026-10-08', SalesGrouping::Day)['orders'])->toBe(2);
});

test('D58 sales weeks start on saturday: friday night and saturday morning fall in different weeks', function (): void {
    ($this->delivered)('2026-10-09 20:30:00');
    ($this->delivered)('2026-10-09 21:30:00');

    expect(($this->sales)('2026-10-03', '2026-10-16', SalesGrouping::Week)['buckets'])->toBe([
        ['period_start' => '2026-10-03', 'orders' => 1, 'total' => '100.00'],
        ['period_start' => '2026-10-10', 'orders' => 1, 'total' => '100.00'],
    ]);
});

test('D58 a week bucket starts on the saturday on or before the range start', function (): void {
    expect(array_column(($this->sales)('2026-10-07', '2026-10-10', SalesGrouping::Week)['buckets'], 'period_start'))->toBe(['2026-10-03', '2026-10-10']);
});

test('D5 the sales report groups by month', function (): void {
    ($this->delivered)('2026-09-20 10:00:00', '40.00');
    ($this->delivered)('2026-10-02 10:00:00', '60.00');

    expect(($this->sales)('2026-09-15', '2026-10-10', SalesGrouping::Month)['buckets'])->toBe([
        ['period_start' => '2026-09-01', 'orders' => 1, 'total' => '40.00'],
        ['period_start' => '2026-10-01', 'orders' => 1, 'total' => '60.00'],
    ]);
});

test('BR-T03 the sales report never counts another tenant', function (): void {
    $this->inTenant(fn (): Order => Order::factory()->delivered()->create(['delivered_at' => now()]), $this->otherTenant());

    expect(($this->sales)('2026-10-10', '2026-10-10', SalesGrouping::Day)['orders'])->toBe(0);
});

test('D5 the overview counts orders per status, zeros included', function (): void {
    Order::factory()->pending()->count(2)->create();
    Order::factory()->delivered()->create();

    expect(app(OverviewQuery::class)->get('Africa/Cairo')['orders_by_status'])->toBe([
        'pending' => 2, 'confirmed' => 0, 'preparing' => 0, 'out_for_delivery' => 0, 'delivered' => 1, 'cancelled' => 0,
    ]);
});

test('D60 today\'s orders are those placed since cairo midnight, the total without cancelled ones', function (): void {
    Order::factory()->create(['items_total' => '100.00', 'order_total' => '100.00', 'placed_at' => CarbonImmutable::parse('2026-10-09 21:00:00', 'UTC')]);
    Order::factory()->cancelled()->create(['items_total' => '50.00', 'order_total' => '50.00', 'placed_at' => now()]);
    Order::factory()->create(['items_total' => '70.00', 'order_total' => '70.00', 'placed_at' => CarbonImmutable::parse('2026-10-09 20:59:59', 'UTC')]);

    expect(app(OverviewQuery::class)->get('Africa/Cairo')['today'])->toBe(['orders' => 2, 'total' => '100.00']);
});

test('BR-INV07 the overview counts pending orders past the stale threshold', function (): void {
    Order::factory()->stale()->count(2)->create();
    Order::factory()->pending()->create();
    Order::factory()->confirmed()->create(['placed_at' => now()->subDays(3)]);

    expect(app(OverviewQuery::class)->get('Africa/Cairo')['stale_pending'])->toBe(2);
});

test('D59 D36 the overview shows the outstanding total and the low-stock count', function (): void {
    ($this->delivered)('2026-10-09 10:00:00', '500.00', '200.00');
    $this->orderableSku(['code' => 'LOW-1'], onHand: 100);
    $this->orderableSku(['code' => 'OK-1'], onHand: 101);

    expect(app(OverviewQuery::class)->get('Africa/Cairo'))
        ->outstanding_total->toBe('300.00')
        ->low_stock_count->toBe(1);
});

test('D5 the outstanding report lists customers who owe, largest debt first', function (): void {
    ($this->delivered)('2026-10-09 10:00:00', '500.00', '200.00');
    ($this->delivered)('2026-10-09 11:00:00', '100.00');
    $big = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201000000777']);
    ($this->delivered)('2026-10-09 12:00:00', '900.00', customerId: $big->id);
    ($this->delivered)('2026-10-09 13:00:00', '50.00', '50.00', $this->makeCustomer(['phone' => '+201000000888'])->id);

    $rows = app(OutstandingReportQuery::class)->paginate(24)->items();

    expect(array_map(fn (object $row): array => [(int) $row->customer_id, $row->customer_name, (int) $row->orders_count, $row->outstanding], $rows))->toBe([
        [$big->id, 'مكتبة الأمل', 1, '900.00'],
        [$this->customer->id, $this->customer->name, 2, '400.00'],
    ]);
});

test('D5 the outstanding report paginates customers', function (): void {
    ($this->delivered)('2026-10-09 10:00:00');
    ($this->delivered)('2026-10-09 10:00:00', customerId: $this->makeCustomer(['phone' => '+201000000777'])->id);

    expect(app(OutstandingReportQuery::class)->paginate(1)->total())->toBe(2);
});

test('D36 the low-stock report lists low skus of products that are not inactive, by code, with product and stock', function (): void {
    $this->orderableSku(['code' => 'B-1'], onHand: 0);
    $this->orderableSku(['code' => 'A-1'], onHand: 100);
    $this->orderableSku(['code' => 'C-1'], onHand: 101);
    $this->orderableSku(['code' => 'D-1'], onHand: 0, status: ProductStatus::Inactive);

    $page = app(LowStockReportQuery::class)->paginate(24);

    expect($page->pluck('code')->all())->toBe(['A-1', 'B-1'])
        ->and($page->first()?->relationLoaded('product'))->toBeTrue()
        ->and($page->first()?->relationLoaded('inventory'))->toBeTrue();
});

test('the status values of the overview follow the order statuses', function (): void {
    expect(array_keys(app(OverviewQuery::class)->get('Africa/Cairo')['orders_by_status']))->toBe(array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()));
});
