<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Data\ReturnFiltersData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Orders\Queries\CustomerOrdersQuery;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\NotificationsQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;
use App\Modules\Orders\Queries\ReturnsQuery;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->freezeSecond();
    $this->customer = Customer::factory()->create(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);

    /** @return list<int> ids of the dashboard page for the filters */
    $this->dashboard = fn (OrderFiltersData $filters = new OrderFiltersData): array => app(DashboardOrdersQuery::class)->paginate($filters, 50)->pluck('id')->all();
});

test('the customer list holds only that customer\'s orders, newest first', function (): void {
    $old = Order::factory()->for($this->customer)->create(['placed_at' => now()->subDay()]);
    $new = Order::factory()->for($this->customer)->create();
    Order::factory()->create();

    expect(app(CustomerOrdersQuery::class)->paginate($this->customer, null, 24)->pluck('id')->all())->toBe([$new->id, $old->id]);
});

test('the customer list filters by status', function (): void {
    $delivered = Order::factory()->for($this->customer)->delivered()->create();
    Order::factory()->for($this->customer)->create();

    expect(app(CustomerOrdersQuery::class)->paginate($this->customer, OrderStatus::Delivered, 24)->pluck('id')->all())->toBe([$delivered->id]);
});

test('the detail loads lines and history for the store, plus the customer for the dashboard', function (): void {
    $order = Order::factory()->withItems()->create();

    $store = app(OrderDetailQuery::class)->forStore(Order::query()->findOrFail($order->id));
    $dashboard = app(OrderDetailQuery::class)->forDashboard(Order::query()->findOrFail($order->id));

    expect($store->relationLoaded('items') && $store->relationLoaded('history'))->toBeTrue()
        ->and($store->relationLoaded('customer'))->toBeFalse()
        ->and($dashboard->relationLoaded('customer') && $dashboard->relationLoaded('items'))->toBeTrue();
});

test('BR-R05 the customer\'s order page loads live returns only, the merchant\'s every return, with their lines', function (): void {
    $order = Order::factory()->delivered()->create();
    $live = OrderReturn::factory()->for($order)->create();
    OrderReturnItem::factory()->for($live)->create();
    OrderReturn::factory()->for($order)->voided()->create(['number' => 2]);

    $store = app(OrderDetailQuery::class)->forStore(Order::query()->findOrFail($order->id));
    $dashboard = app(OrderDetailQuery::class)->forDashboard(Order::query()->findOrFail($order->id));

    expect($store->returns->pluck('id')->all())->toBe([$live->id])
        ->and($store->returns->first()?->items->first()?->relationLoaded('orderItem'))->toBeTrue()
        ->and($dashboard->returns)->toHaveCount(2);
});

test('BR-R05 the returns list is newest returned first, voided included, with order, customer and lines', function (): void {
    $old = OrderReturn::factory()->create(['returned_at' => now()->subDay()]);
    $new = OrderReturn::factory()->voided()->create();
    $this->inTenant(fn (): OrderReturn => OrderReturn::factory()->create(), $this->otherTenant());

    $page = app(ReturnsQuery::class)->paginate(new ReturnFiltersData, 24);

    expect($page->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and($page->first()?->relationLoaded('items'))->toBeTrue()
        ->and($page->first()?->order->relationLoaded('customer'))->toBeTrue();
});

test('the returns list filters by returned time, customer and order number or customer name', function (): void {
    $mine = Order::factory()->for($this->customer)->delivered()->create(['number' => 1024]);
    $match = OrderReturn::factory()->for($mine)->create(['returned_at' => now()->subHour()]);
    $early = OrderReturn::factory()->for($mine)->create(['number' => 2, 'returned_at' => now()->subDays(3)]);
    $other = OrderReturn::factory()->create(['returned_at' => now()->subHour()]);
    $filter = fn (ReturnFiltersData $filters): array => app(ReturnsQuery::class)->paginate($filters, 24)->pluck('id')->all();

    expect($filter(new ReturnFiltersData(from: now()->subDay())))->toBe([$other->id, $match->id])
        ->and($filter(new ReturnFiltersData(to: now()->subDays(2))))->toBe([$early->id])
        ->and($filter(new ReturnFiltersData(customerId: $this->customer->id)))->toBe([$match->id, $early->id])
        ->and($filter(new ReturnFiltersData(search: '#1024')))->toBe([$match->id, $early->id])
        ->and($filter(new ReturnFiltersData(search: 'الأمل')))->toBe([$match->id, $early->id]);
});

test('BR-R06 an order\'s returns are listed by number, voided included, with their lines', function (): void {
    $order = Order::factory()->delivered()->create();
    $second = OrderReturn::factory()->for($order)->voided()->create(['number' => 2]);
    $first = OrderReturn::factory()->for($order)->create(['number' => 1]);
    OrderReturn::factory()->create();

    $returns = app(ReturnsQuery::class)->forOrder($order);

    expect($returns->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($returns->first()?->relationLoaded('items'))->toBeTrue();
});

test('BR-R02 the returnable lines show what was ordered, what came back by live returns and what is left', function (): void {
    $order = Order::factory()->delivered()->create();
    $line = OrderItem::factory()->for($order)->create(['quantity' => 6, 'unit_price' => '85.00']);
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($order))->create(['order_item_id' => $line->id, 'quantity' => 2]);
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($order)->voided()->state(['number' => 2]))->create(['order_item_id' => $line->id, 'quantity' => 4]);

    expect(app(ReturnsQuery::class)->returnable($order))->toBe([[
        'order_item_id' => $line->id, 'product_name' => $line->product_name, 'sku_code' => $line->sku_code, 'unit' => $line->unit_name,
        'unit_price' => '85.00', 'ordered' => 6, 'delivered' => 6, 'returned' => 2, 'returnable' => 4,
    ]]);
});

test('D85 a returnable line shows what one unit returns at: its price less the order discount share', function (): void {
    $order = Order::factory()->delivered()->create(['items_total' => '510.00', 'discount_type' => 'amount', 'discount_value' => '10', 'discount_amount' => '10.00', 'discount_reason' => 'عميل دائم', 'order_total' => '500.00']);
    OrderItem::factory()->for($order)->create(['quantity' => 6, 'unit_price' => '85.00']);

    expect(app(ReturnsQuery::class)->returnable($order)[0]['unit_price'])->toBe('83.33');
});

test('the dashboard list holds every order of the tenant, newest first, with the customer', function (): void {
    $old = Order::factory()->create(['placed_at' => now()->subDay()]);
    $new = Order::factory()->create();
    $this->inTenant(fn () => Order::factory()->create(), $this->otherTenant());

    $page = app(DashboardOrdersQuery::class)->paginate(new OrderFiltersData, 24);

    expect($page->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and($page->getCollection()->every(fn (Order $order): bool => $order->relationLoaded('customer')))->toBeTrue();
});

test('the dashboard list filters by status, collection status and customer', function (): void {
    $match = Order::factory()->for($this->customer)->confirmed()->create(['collection_status' => CollectionStatus::PartiallyCollected]);
    Order::factory()->for($this->customer)->confirmed()->create();
    Order::factory()->for($this->customer)->create(['collection_status' => CollectionStatus::PartiallyCollected]);
    Order::factory()->confirmed()->create(['collection_status' => CollectionStatus::PartiallyCollected]);

    expect(($this->dashboard)(new OrderFiltersData(status: OrderStatus::Confirmed, collectionStatus: CollectionStatus::PartiallyCollected, customerId: $this->customer->id)))->toBe([$match->id]);
});

test('the dashboard list filters by placing day, both ends included', function (): void {
    $first = Order::factory()->create(['placed_at' => '2026-10-01 00:00:00']);
    $last = Order::factory()->create(['placed_at' => '2026-10-03 23:59:59']);
    Order::factory()->create(['placed_at' => '2026-09-30 23:59:59']);
    Order::factory()->create(['placed_at' => '2026-10-04 00:00:00']);

    expect(($this->dashboard)(new OrderFiltersData(from: CarbonImmutable::parse('2026-10-01'), to: CarbonImmutable::parse('2026-10-03')->endOfDay())))->toBe([$last->id, $first->id]);
});

test('the dashboard search finds the order number, customer name or phone, or recipient name', function (string $q): void {
    $match = Order::factory()->for($this->customer)->create(['number' => 1024, 'delivery_name' => 'أحمد علي']);
    Order::factory()->create(['number' => 2048, 'delivery_name' => 'محمود']);

    expect(($this->dashboard)(new OrderFiltersData(search: $q)))->toBe([$match->id]);
})->with(['number' => ['1024'], 'number with hash' => ['#1024'], 'customer name' => ['الأمل'], 'customer phone' => ['01012345678'], 'recipient name' => ['أحمد']]);

test('BR-INV07 D10 the stale filter keeps pending orders older than the tenant\'s threshold', function (): void {
    $stale = Order::factory()->stale(25)->create();
    Order::factory()->stale(23)->create();
    Order::factory()->confirmed()->create(['placed_at' => now()->subDays(2)]);

    expect(($this->dashboard)(new OrderFiltersData(stale: true)))->toBe([$stale->id]);
});

test('BR-INV07 the stale threshold follows the tenant setting', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['stale_pending_hours' => 2]);
    $stale = Order::factory()->stale(3)->create();

    expect(($this->dashboard)(new OrderFiltersData(stale: true)))->toBe([$stale->id])
        ->and(app(DashboardOrdersQuery::class)->pendingClock()->staleBefore->equalTo(now()->subHours(2)))->toBeTrue();
});

test('D81 the pending clock carries the store\'s expiry hours, null while off', function (): void {
    expect(app(DashboardOrdersQuery::class)->pendingClock()->expiryHours)->toBeNull();

    TenantSetting::query()->whereKey($this->tenant->id)->update(['pending_expiry_hours' => 36]);

    expect(app(DashboardOrdersQuery::class)->pendingClock()->expiryHours)->toBe(36);
});

test('the dashboard list sorts by the chosen column', function (): void {
    $small = Order::factory()->create(['order_total' => '10.00']);
    $big = Order::factory()->create(['order_total' => '99.00']);

    expect(($this->dashboard)(new OrderFiltersData(sort: ['order_total', 'desc'])))->toBe([$big->id, $small->id])
        ->and(($this->dashboard)(new OrderFiltersData(sort: ['order_total', 'asc'])))->toBe([$small->id, $big->id]);
});

test('BR-N02 notifications come newest first, optionally unread only, with the unread count', function (): void {
    $read = MerchantNotification::factory()->read()->create();
    $unread = MerchantNotification::factory()->create();

    expect(app(NotificationsQuery::class)->paginate(false, 24)->pluck('id')->all())->toBe([$unread->id, $read->id])
        ->and(app(NotificationsQuery::class)->paginate(true, 24)->pluck('id')->all())->toBe([$unread->id])
        ->and(app(NotificationsQuery::class)->unreadCount())->toBe(1);
});
