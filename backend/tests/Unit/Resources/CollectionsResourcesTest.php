<?php

declare(strict_types=1);

use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Collections\Http\Resources\CustomerCreditResource;
use App\Modules\Collections\Http\Resources\LedgerRowResource;
use App\Modules\Collections\Http\Resources\StatementResource;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Reports\Data\BundleReportData;
use App\Modules\Reports\Data\SalesReportData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Http\Resources\BundleReportResource;
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
        'order' => ['id' => $this->order->id, 'number' => $this->order->number, 'order_total' => '500.00', 'net_total' => '500.00', 'collected_amount' => '200.00', 'collection_status' => 'partially_collected'],
        'customer' => ['id' => $this->customer->id, 'name' => $this->customer->name],
        'kind' => 'payment',
        'kind_label' => 'تحصيل',
        'order_return_id' => null,
        'customer_credit_id' => null,
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

    $detail = rendered(new DashboardOrderResource($order, new App\Modules\Orders\Data\PendingClockData(now()->toImmutable(), null), $collections), dashboardRequest('/api/dashboard/orders/1'));

    expect($detail['collections'])->toHaveCount(1)
        ->and(array_keys($detail['collections'][0]))->not->toContain('customer')
        ->and($detail['collections'][0]['id'])->toBe($this->collection->id);
});

test('BR-C08 a ledger row renders its date, kind, references and money as strings', function (): void {
    $row = (object) ['occurred_at' => '2026-10-10 09:00:00', 'type' => 'return', 'order_number' => '1024', 'return_number' => '2', 'debit' => '0', 'credit' => '85.5', 'balance' => '-10.5'];

    expect(rendered(new LedgerRowResource($row), dashboardRequest()))->toBe([
        'date' => '2026-10-10T09:00:00+00:00',
        'type' => 'return',
        'type_label' => 'مرتجع',
        'order_number' => 1024,
        'return_number' => 2,
        'debit' => '0.00',
        'credit' => '85.50',
        'balance' => '-10.50',
    ]);
});

test('BR-C08 an info row without an order keeps null references', function (): void {
    $row = (object) ['occurred_at' => '2026-10-10 09:00:00', 'type' => 'credit_created', 'order_number' => null, 'return_number' => null, 'debit' => '0', 'credit' => '0', 'balance' => '0'];

    expect(rendered(new LedgerRowResource($row), dashboardRequest()))->toMatchArray(['order_number' => null, 'return_number' => null, 'type_label' => 'رصيد للعميل']);
});

test('BR-C07 a customer credit renders its amount, what is left, its source and void details', function (): void {
    $credit = CustomerCredit::factory()->for($this->customer)->voided('خطأ')->create(['amount' => '90.00', 'remaining' => '90.00', 'created_by' => $this->owner->id, 'voided_by' => $this->owner->id])->fresh();

    expect(rendered(new CustomerCreditResource($credit), dashboardRequest()))->toBe([
        'id' => $credit->id,
        'customer_id' => $this->customer->id,
        'source_return_id' => null,
        'amount' => '90.00',
        'remaining' => '90.00',
        'note' => null,
        'created_by' => $this->owner->id,
        'created_at' => now()->toIso8601String(),
        'is_voided' => true,
        'voided_at' => now()->toIso8601String(),
        'void_reason' => 'خطأ',
        'voided_by' => $this->owner->id,
    ]);
});
test('D24 the statement head names the customer and carries the summary', function (): void {
    $summary = ['orders_count' => 1, 'total' => '500.00', 'collected' => '200.00', 'outstanding' => '300.00', 'credit_limit' => null, 'payment_terms_days' => null, 'available_credit' => null, 'credit_balance' => '0.00', 'overdue' => '300.00'];

    expect(new StatementResource($this->customer, $summary)->resolve(dashboardRequest()))->toBe([
        'customer' => ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone],
        'summary' => $summary,
    ]);
});

test('D5 BR-C06 the overview renders its figures', function (): void {
    $overview = ['orders_by_status' => ['pending' => 1], 'today' => ['orders' => 1, 'total' => '10.00', 'returns' => '0.00'], 'stale_pending' => 0, 'outstanding_total' => '0.00', 'overdue_total' => '0.00', 'overdue_customers' => 0, 'low_stock_count' => 2];

    expect(rendered(new OverviewResource($overview), dashboardRequest()))->toBe($overview);
});

test('D5 the sales report renders its range, grouping, buckets and totals', function (): void {
    $sales = ['buckets' => [['period_start' => '2026-10-10', 'orders' => 1, 'gross' => '10.00', 'returns' => '4.00', 'net' => '6.00']], 'orders' => 1, 'gross' => '10.00', 'returns' => '4.00', 'net' => '6.00'];

    expect(rendered(new SalesReportResource($sales, new SalesReportData('2026-10-10', '2026-10-10', SalesGrouping::Week, 'Africa/Cairo')), dashboardRequest()))->toBe([
        'from' => '2026-10-10',
        'to' => '2026-10-10',
        'group' => 'week',
        'buckets' => [['period_start' => '2026-10-10', 'orders' => 1, 'gross' => '10.00', 'returns' => '4.00', 'net' => '6.00']],
        'totals' => ['orders' => 1, 'gross' => '10.00', 'returns' => '4.00', 'net' => '6.00'],
    ]);
});

test('D93 the bundle report renders its range, bundles and components', function (): void {
    $report = [
        'bundles' => [['product_id' => 3, 'name' => 'باقة أولى', 'sku_code' => 'SET-1', 'quantity' => 2, 'total' => '300.00']],
        'components' => [['sku_id' => 5, 'sku_code' => 'ADW-SCI-1', 'product_name' => 'علوم', 'quantity_base' => 40]],
    ];

    expect(rendered(new BundleReportResource($report, new BundleReportData('2026-10-01', '2026-10-10', 'Africa/Cairo')), dashboardRequest()))->toBe([
        'from' => '2026-10-01',
        'to' => '2026-10-10',
        'bundles' => [['product_id' => 3, 'name' => 'باقة أولى', 'sku_code' => 'SET-1', 'quantity' => 2, 'total' => '300.00']],
        'components' => [['sku_id' => 5, 'sku_code' => 'ADW-SCI-1', 'product_name' => 'علوم', 'quantity_base' => 40]],
    ]);
});

test('D59 BR-C06 an outstanding row renders the customer, money as strings and the aging', function (): void {
    $row = (object) ['customer_id' => 7, 'customer_name' => 'مكتبة', 'customer_phone' => '+201000000777', 'orders_count' => '2', 'total' => '800', 'collected' => '200.5', 'outstanding' => '599.5', 'aging_current' => '100', 'aging_0_30' => '0', 'aging_31_60' => '499.5', 'aging_61_90' => '0', 'aging_over_90' => '0', 'overdue' => '499.5'];

    expect(rendered(new OutstandingCustomerResource($row), dashboardRequest()))->toBe([
        'customer' => ['id' => 7, 'name' => 'مكتبة', 'phone' => '+201000000777'],
        'orders' => 2,
        'total' => '800.00',
        'collected' => '200.50',
        'outstanding' => '599.50',
        'aging' => ['current' => '100.00', '0_30' => '0.00', '31_60' => '499.50', '61_90' => '0.00', 'over_90' => '0.00'],
        'overdue' => '499.50',
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
