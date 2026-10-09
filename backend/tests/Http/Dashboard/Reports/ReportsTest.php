<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

/*
| The four D5 reports. 2026-10-10 is a Saturday; Cairo is UTC+3 in October (D60).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer();
    $this->delivered = fn (string $at, string $total = '100.00', string $collected = '0.00') => $this->inTenant(fn (): Order => Order::factory()->delivered()->for($this->customer)->create([
        'items_total' => $total, 'order_total' => $total, 'collected_amount' => $collected, 'collection_status' => CollectionStatus::for($collected, $total), 'delivered_at' => CarbonImmutable::parse($at, 'UTC'),
    ]));
});

test('D5 the overview answers every figure', function (): void {
    ($this->delivered)('2026-10-09 10:00:00', '500.00', '200.00');
    $this->inTenant(fn () => Order::factory()->stale()->create());
    $this->orderableSku(['code' => 'LOW-1'], onHand: 0);

    $this->tenantJson('GET', '/api/dashboard/reports/overview')
        ->assertOk()
        ->assertExactJson(['data' => [
            'orders_by_status' => ['pending' => 1, 'confirmed' => 0, 'preparing' => 0, 'out_for_delivery' => 0, 'delivered' => 1, 'cancelled' => 0],
            'today' => ['orders' => 1, 'total' => '500.00'],
            'stale_pending' => 1,
            'outstanding_total' => '300.00',
            'low_stock_count' => 1,
        ]]);
});

test('D5 D58 the sales report groups delivered orders into saturday weeks', function (): void {
    ($this->delivered)('2026-10-09 20:30:00', '40.00');
    ($this->delivered)('2026-10-09 21:30:00', '60.00');

    $this->tenantJson('GET', '/api/dashboard/reports/sales?from=2026-10-03&to=2026-10-16&group=week')
        ->assertOk()
        ->assertExactJson(['data' => [
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'group' => 'week',
            'buckets' => [
                ['period_start' => '2026-10-03', 'orders' => 1, 'total' => '40.00'],
                ['period_start' => '2026-10-10', 'orders' => 1, 'total' => '60.00'],
            ],
            'totals' => ['orders' => 2, 'total' => '100.00'],
        ]]);
});

test('D5 the sales report defaults to the last 30 days by day', function (): void {
    $this->tenantJson('GET', '/api/dashboard/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.from', '2026-09-11')
        ->assertJsonPath('data.to', '2026-10-10')
        ->assertJsonPath('data.group', 'day')
        ->assertJsonCount(30, 'data.buckets');
});

test('every sales filter is validated', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/reports/sales?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'from not a day' => ['from=2026/10/01', 'from'],
    'to before from' => ['from=2026-10-05&to=2026-10-04', 'to'],
    'unknown grouping' => ['group=year', 'group'],
]);

test('D5 the outstanding report lists what each customer owes, paginated', function (): void {
    ($this->delivered)('2026-10-09 10:00:00', '500.00', '200.00');

    $this->tenantJson('GET', '/api/dashboard/reports/outstanding')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'customer' => ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone],
            'orders' => 1, 'total' => '500.00', 'collected' => '200.00', 'outstanding' => '300.00',
        ])
        ->assertJsonPath('meta.total', 1)
        ->assertExactJsonStructure(['data' => ['*' => ['customer' => ['id', 'name', 'phone'], 'orders', 'total', 'collected', 'outstanding']], 'links', 'meta']);
});

test('D36 the low-stock report lists low skus, paginated', function (): void {
    $sku = $this->orderableSku(['code' => 'LOW-1'], onHand: 60);
    $this->orderableSku(['code' => 'OK-1'], onHand: 400);

    $this->tenantJson('GET', '/api/dashboard/reports/low-stock')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku_id', $sku->id)
        ->assertJsonPath('data.0.available', 60)
        ->assertJsonPath('data.0.threshold', 100)
        ->assertExactJsonStructure(['data' => ['*' => ['sku_id', 'product_id', 'product_name', 'code', 'name', 'available', 'moq', 'conversion_factor', 'threshold']], 'links', 'meta']);
});

test('the paginated reports validate the page size', function (string $report): void {
    $this->tenantJson('GET', "/api/dashboard/reports/{$report}?per_page=500")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['per_page']]]);
})->with(['outstanding', 'low-stock']);

test('BR-T03 no report counts another tenant', function (): void {
    $other = $this->otherTenant();
    $this->inTenant(fn () => Order::factory()->delivered()->create(['items_total' => '70.00', 'order_total' => '70.00', 'delivered_at' => now()]), $other);
    $this->orderableSku(['code' => 'LOW-9'], onHand: 0, tenant: $other);

    $this->tenantJson('GET', '/api/dashboard/reports/overview')->assertJsonPath('data.outstanding_total', '0.00')->assertJsonPath('data.low_stock_count', 0)->assertJsonPath('data.orders_by_status.delivered', 0);
    $this->tenantJson('GET', '/api/dashboard/reports/sales?from=2026-10-10&to=2026-10-10')->assertJsonPath('data.totals.orders', 0);
    $this->tenantJson('GET', '/api/dashboard/reports/outstanding')->assertJsonCount(0, 'data');
    $this->tenantJson('GET', '/api/dashboard/reports/low-stock')->assertJsonCount(0, 'data');
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (string $report): void {
    $this->tenantJson('GET', "/api/dashboard/reports/{$report}", tenant: $this->otherTenant())->assertUnauthorized();
})->with(['overview', 'sales', 'outstanding', 'low-stock']);
