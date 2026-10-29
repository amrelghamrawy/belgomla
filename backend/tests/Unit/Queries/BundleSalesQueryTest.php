<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Reports\Data\BundleReportData;
use App\Modules\Reports\Queries\BundleSalesQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| D93 bundle sales and component consumption, with frozen time. Cairo is UTC+3 in October (D60).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->science = Product::factory()->withSku(['code' => 'ADW-SCI-1', 'conversion_factor' => 20])->create(['name' => 'علوم'])->skus()->sole();
    $this->math = Product::factory()->withSku(['code' => 'ADW-MATH-1'])->create(['name' => 'رياضيات'])->skus()->sole();
    $this->set = Product::factory()->bundle()->withSku(['code' => 'SET-1'])->create(['name' => 'باقة أولى'])->skus()->sole();
    $this->components = [$this->defaultUnitId($this->science) => 1, $this->defaultUnitId($this->math) => 2];
    $this->report = fn (string $from = '2026-10-01', string $to = '2026-10-10'): array => app(BundleSalesQuery::class)->get(new BundleReportData($from, $to, 'Africa/Cairo'));
});

test('D93 bundles delivered in the range are summed per bundle, components per sku in base units', function (): void {
    $this->bundleOrder($this->set, $this->components, bundles: 2, price: '150.00', delivered: 2, deliveredAt: '2026-10-03 10:00:00');
    $this->bundleOrder($this->set, $this->components, bundles: 3, price: '150.00', delivered: 3, deliveredAt: '2026-10-09 10:00:00');

    expect(($this->report)())->toBe([
        'bundles' => [['product_id' => $this->set->product_id, 'name' => 'باقة أولى', 'sku_code' => 'SET-1', 'quantity' => 5, 'total' => '750.00']],
        'components' => [
            ['sku_id' => $this->science->id, 'sku_code' => 'ADW-SCI-1', 'product_name' => 'علوم', 'quantity_base' => 100],
            ['sku_id' => $this->math->id, 'sku_code' => 'ADW-MATH-1', 'product_name' => 'رياضيات', 'quantity_base' => 10],
        ],
    ]);
});

test('D93 a partially delivered bundle counts what went out', function (): void {
    $this->bundleOrder($this->set, $this->components, bundles: 3, price: '150.00', delivered: 1, deliveredAt: '2026-10-05 10:00:00');

    $report = ($this->report)();

    expect($report['bundles'][0]['quantity'])->toBe(1)
        ->and($report['bundles'][0]['total'])->toBe('150.00')
        ->and(array_column($report['components'], 'quantity_base'))->toBe([20, 2]);
});

test('D5 D93 only orders delivered within the business days of the range count', function (array $order, ?int $delivered, ?string $at): void {
    $this->bundleOrder($this->set, $this->components, bundles: 2, delivered: $delivered, deliveredAt: $at, order: $order);

    expect(($this->report)())->toBe(['bundles' => [], 'components' => []]);
})->with([
    'cancelled' => [['status' => 'cancelled', 'cancelled_at' => '2026-10-05 10:00:00', 'cancelled_by_type' => 'tenant_user', 'cancel_reason' => 'نفاد'], null, null],
    'not delivered yet' => [['status' => 'out_for_delivery', 'confirmed_at' => '2026-10-05 10:00:00'], null, null],
    'delivered the cairo day before the range' => [[], 2, '2026-09-30 20:59:59'],
    'delivered after the range' => [[], 2, '2026-10-10 21:00:00'],
]);

test('D60 an order delivered just after cairo midnight on the first day counts', function (): void {
    $this->bundleOrder($this->set, $this->components, bundles: 2, delivered: 2, deliveredAt: '2026-09-30 21:00:00');

    expect(array_column(($this->report)()['bundles'], 'quantity'))->toBe([2]);
});

test('BR-O12 removed lines are left out', function (): void {
    $order = $this->bundleOrder($this->set, $this->components, bundles: 2, delivered: 2, deliveredAt: '2026-10-05 10:00:00');
    OrderItem::query()->where('order_id', $order->id)->update(['removed_at' => now(), 'reserved' => false, 'delivered_quantity' => null]);

    expect(($this->report)())->toBe(['bundles' => [], 'components' => []]);
});

test('D93 a bundle of which nothing went out is left out', function (): void {
    $this->bundleOrder($this->set, $this->components, bundles: 2, delivered: 0, deliveredAt: '2026-10-05 10:00:00');

    expect(($this->report)())->toBe(['bundles' => [], 'components' => []]);
});

test('D93 the name and code are the latest line\'s snapshot', function (): void {
    $older = $this->bundleOrder($this->set, $this->components, delivered: 1, deliveredAt: '2026-10-03 10:00:00');
    OrderItem::query()->where('order_id', $older->id)->update(['product_name' => 'اسم قديم', 'sku_code' => 'OLD-1']);
    $this->bundleOrder($this->set, $this->components, delivered: 1, deliveredAt: '2026-10-04 10:00:00');

    $report = ($this->report)();

    expect([$report['bundles'][0]['name'], $report['bundles'][0]['sku_code']])->toBe(['باقة أولى', 'SET-1'])
        ->and(array_column($report['components'], 'product_name'))->toBe(['علوم', 'رياضيات']);
});

test('D93 the most sold bundle comes first', function (): void {
    $other = Product::factory()->bundle()->withSku(['code' => 'SET-2'])->create(['name' => 'باقة ثانية'])->skus()->sole();
    $this->bundleOrder($this->set, [$this->defaultUnitId($this->math) => 1], delivered: 1, deliveredAt: '2026-10-03 10:00:00');
    $this->bundleOrder($other, [$this->defaultUnitId($this->math) => 1], bundles: 4, delivered: 4, deliveredAt: '2026-10-03 10:00:00');

    expect(array_column(($this->report)()['bundles'], 'sku_code'))->toBe(['SET-2', 'SET-1']);
});

test('loose lines of a component sold on their own are not bundle consumption', function (): void {
    $order = $this->bundleOrder($this->set, $this->components, delivered: 1, deliveredAt: '2026-10-05 10:00:00');
    OrderItem::factory()->for($order)->delivered()->create(['sku_id' => $this->math->id, 'quantity' => 7]);

    expect(array_column(($this->report)()['components'], 'quantity_base'))->toBe([20, 2]);
});
