<?php

declare(strict_types=1);

use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Collections\Http\Resources\StatementOrderResource;
use App\Modules\Collections\Http\Resources\StatementResource;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Reports\Data\SalesReportData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Http\Resources\LowStockSkuResource;
use App\Modules\Reports\Http\Resources\OutstandingCustomerResource;
use App\Modules\Reports\Http\Resources\OverviewResource;
use App\Modules\Reports\Http\Resources\SalesReportResource;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->freezeSecond();
    $this->owner = $this->makeOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->order = Order::factory()->delivered()->for($this->customer)->create(['items_total' => '500.00', 'order_total' => '500.00', 'collected_amount' => '200.00', 'collection_status' => 'partially_collected']);
    $this->collection = CashCollection::factory()->for($this->order)->voided('مكرر')->create(['amount' => '200.00', 'recorded_by' => $this->owner->id, 'voided_by' => $this->owner->id, 'note' => 'دفعة'])->fresh();
});

test('BR-C02 a collection renders its order balance, customer, money as a string and void details', function (): void {
    $this->order->load('customer');

    expect(rendered(new CollectionResource($this->collection->setRelation('order', $this->order)), dashboardRequest('/api/dashboard/collections')))->toBe([
        'id' => $this->collection->id,
        'order' => ['id' => $this->order->id, 'number' => $this->order->number, 'order_total' => '500.00', 'collected_amount' => '200.00', 'collection_status' => 'partially_collected'],
        'customer' => ['id' => $this->customer->id, 'name' => $this->customer->name],
        'amount' => '200.00',
        'method' => 'cash',
        'method_label' => 'نقدي',
        'collected_at' => now()->toIso8601String(),
        'recorded_by' => $this->owner->id,
        'note' => 'دفعة',
        'is_voided' => true,
        'voided_at' => now()->toIso8601String(),
        'void_reason' => 'مكرر',
        'voided_by' => $this->owner->id,
        'created_at' => now()->toIso8601String(),
    ]);
});

test('a collection leaves the customer out when the order comes without it', function (): void {
    expect(rendered(new CollectionResource($this->collection->setRelation('order', $this->order->withoutRelations())), dashboardRequest()))->not->toHaveKey('customer');
});

test('BR-C02 the dashboard order detail lists its collections without the customer', function (): void {
    $order = $this->order->load(['items', 'history', 'customer']);
    $collections = [new CollectionResource($this->collection->setRelation('order', $order->withoutRelations()))];

    $detail = rendered(new DashboardOrderResource($order, now()->toImmutable(), $collections), dashboardRequest('/api/dashboard/orders/1'));

    expect($detail['collections'])->toHaveCount(1)
        ->and(array_keys($detail['collections'][0]))->not->toContain('customer')
        ->and($detail['collections'][0]['id'])->toBe($this->collection->id);
});

test('D24 a statement order renders its balance', function (): void {
    expect(rendered(new StatementOrderResource($this->order), dashboardRequest()))->toBe([
        'number' => $this->order->number,
        'status' => 'delivered',
        'status_label' => 'تم التوصيل',
        'placed_at' => now()->toIso8601String(),
        'delivered_at' => now()->toIso8601String(),
        'order_total' => '500.00',
        'collected_amount' => '200.00',
        'balance' => '300.00',
        'collection_status' => 'partially_collected',
    ]);
});

test('D24 the statement head names the customer and carries the summary', function (): void {
    $summary = ['orders_count' => 1, 'total' => '500.00', 'collected' => '200.00', 'outstanding' => '300.00'];

    expect(new StatementResource($this->customer, $summary)->resolve(dashboardRequest()))->toBe([
        'customer' => ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone],
        'summary' => $summary,
    ]);
});

test('D5 the overview renders its five figures', function (): void {
    $overview = ['orders_by_status' => ['pending' => 1], 'today' => ['orders' => 1, 'total' => '10.00'], 'stale_pending' => 0, 'outstanding_total' => '0.00', 'low_stock_count' => 2];

    expect(rendered(new OverviewResource($overview), dashboardRequest()))->toBe($overview);
});

test('D5 the sales report renders its range, grouping, buckets and totals', function (): void {
    $sales = ['buckets' => [['period_start' => '2026-10-10', 'orders' => 1, 'total' => '10.00']], 'orders' => 1, 'total' => '10.00'];

    expect(rendered(new SalesReportResource($sales, new SalesReportData('2026-10-10', '2026-10-10', SalesGrouping::Week, 'Africa/Cairo')), dashboardRequest()))->toBe([
        'from' => '2026-10-10',
        'to' => '2026-10-10',
        'group' => 'week',
        'buckets' => [['period_start' => '2026-10-10', 'orders' => 1, 'total' => '10.00']],
        'totals' => ['orders' => 1, 'total' => '10.00'],
    ]);
});

test('D59 an outstanding row renders the customer and money as strings', function (): void {
    $row = (object) ['customer_id' => 7, 'customer_name' => 'مكتبة', 'customer_phone' => '+201000000777', 'orders_count' => '2', 'total' => '800', 'collected' => '200.5', 'outstanding' => '599.5'];

    expect(rendered(new OutstandingCustomerResource($row), dashboardRequest()))->toBe([
        'customer' => ['id' => 7, 'name' => 'مكتبة', 'phone' => '+201000000777'],
        'orders' => 2,
        'total' => '800.00',
        'collected' => '200.50',
        'outstanding' => '599.50',
    ]);
});

test('D36 a low-stock sku renders its stock against the threshold', function (): void {
    $sku = $this->orderableSku(['code' => 'LOW-1'], onHand: 60)->load(['product', 'inventory']);

    expect(rendered(new LowStockSkuResource($sku), dashboardRequest()))->toBe([
        'sku_id' => $sku->id,
        'product_id' => $sku->product_id,
        'product_name' => 'الأضواء علوم',
        'code' => 'LOW-1',
        'name' => $sku->name,
        'available' => 60,
        'moq' => 5,
        'conversion_factor' => 20,
        'threshold' => 100,
    ]);
});

test('D36 a sku without a stock row has nothing available', function (): void {
    $sku = $this->orderableSku(['code' => 'LOW-2'])->setRelation('inventory', null)->load('product');

    expect(rendered(new LowStockSkuResource($sku), dashboardRequest())['available'])->toBe(0);
});
