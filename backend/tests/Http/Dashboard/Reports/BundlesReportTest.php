<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\Enums\TenantUserRole;
use Carbon\CarbonImmutable;

/*
| GET /api/dashboard/reports/bundles (BR-P15, D93). 2026-10-10 is a Saturday; Cairo is UTC+3 in October (D60).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->owner = $this->actingAsOwner();
    [$this->book, $this->set] = $this->inTenant(fn (): array => [
        Product::factory()->withSku(['code' => 'ADW-SCI-1', 'conversion_factor' => 20])->create(['name' => 'علوم'])->skus()->sole(),
        Product::factory()->bundle()->withSku(['code' => 'SET-1'])->create(['name' => 'باقة أولى'])->skus()->sole(),
    ]);
    $this->components = fn (): array => [$this->inTenant(fn (): int => $this->defaultUnitId($this->book)) => 2];
});

test('D93 the report lists the bundles delivered in the range and the component units they consumed', function (): void {
    $this->bundleOrder($this->set, ($this->components)(), bundles: 3, price: '150.00', delivered: 3, deliveredAt: '2026-10-09 10:00:00');

    $this->tenantJson('GET', '/api/dashboard/reports/bundles?from=2026-10-01&to=2026-10-10')
        ->assertOk()
        ->assertExactJson(['data' => [
            'from' => '2026-10-01',
            'to' => '2026-10-10',
            'bundles' => [['product_id' => $this->set->product_id, 'name' => 'باقة أولى', 'sku_code' => 'SET-1', 'quantity' => 3, 'total' => '450.00']],
            'components' => [['sku_id' => $this->book->id, 'sku_code' => 'ADW-SCI-1', 'product_name' => 'علوم', 'quantity_base' => 120]],
        ]]);
});

test('D93 a partially delivered bundle counts what went out', function (): void {
    $this->bundleOrder($this->set, ($this->components)(), bundles: 3, price: '150.00', delivered: 1, deliveredAt: '2026-10-09 10:00:00');

    $this->tenantJson('GET', '/api/dashboard/reports/bundles')
        ->assertOk()
        ->assertJsonPath('data.bundles.0.quantity', 1)
        ->assertJsonPath('data.bundles.0.total', '150.00')
        ->assertJsonPath('data.components.0.quantity_base', 40);
});

test('D93 a cancelled or undelivered order is not counted', function (array $order): void {
    $this->bundleOrder($this->set, ($this->components)(), bundles: 2, order: $order);

    $this->tenantJson('GET', '/api/dashboard/reports/bundles')
        ->assertOk()
        ->assertJsonPath('data.bundles', [])
        ->assertJsonPath('data.components', []);
})->with([
    'cancelled' => [['status' => 'cancelled', 'cancelled_at' => '2026-10-09 10:00:00', 'cancelled_by_type' => 'tenant_user', 'cancel_reason' => 'نفاد']],
    'out for delivery' => [['status' => 'out_for_delivery', 'confirmed_at' => '2026-10-09 10:00:00']],
]);

test('D5 D60 the bundle report defaults to the last 30 business days up to today', function (): void {
    $this->tenantJson('GET', '/api/dashboard/reports/bundles')
        ->assertOk()
        ->assertJsonPath('data.from', '2026-09-11')
        ->assertJsonPath('data.to', '2026-10-10');
});

test('every bundle report filter is validated', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/reports/bundles?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'from not a day' => ['from=2026/10/01', 'from'],
    'to not a day' => ['to=10-10-2026', 'to'],
    'to before from' => ['from=2026-10-05&to=2026-10-04', 'to'],
]);

test('a guest is refused', function (): void {
    $this->app['auth']->forgetGuards();

    $this->tenantJson('GET', '/api/dashboard/reports/bundles')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('D82 a role without reports.view is refused', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Warehouse]));

    $this->tenantJson('GET', '/api/dashboard/reports/bundles')->assertForbidden();
});

test('BR-T03 the report never counts another tenant\'s bundles', function (): void {
    $other = $this->otherTenant();
    $set = $this->inTenant(fn (): Sku => Product::factory()->bundle()->withSku(['code' => 'SET-9'])->create()->skus()->sole(), $other);
    $this->bundleOrder($set, [], bundles: 2, delivered: 2, deliveredAt: '2026-10-09 10:00:00', tenant: $other);

    $this->tenantJson('GET', '/api/dashboard/reports/bundles')
        ->assertOk()
        ->assertJsonPath('data.bundles', []);
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (): void {
    $this->tenantJson('GET', '/api/dashboard/reports/bundles', tenant: $this->otherTenant())->assertUnauthorized();
});

test('the bundle report matches its documented shape', function (): void {
    $this->bundleOrder($this->set, ($this->components)(), delivered: 1, deliveredAt: '2026-10-09 10:00:00');

    $report = $this->tenantJson('GET', '/api/dashboard/reports/bundles')->assertOk()->json('data');

    expectDocumentedShape($report, 'BundleReport');
});
