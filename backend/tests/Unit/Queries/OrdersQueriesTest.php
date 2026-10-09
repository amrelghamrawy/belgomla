<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\CustomerOrdersQuery;
use App\Modules\Orders\Queries\DashboardOrdersQuery;
use App\Modules\Orders\Queries\NotificationsQuery;
use App\Modules\Orders\Queries\OrderDetailQuery;
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
        ->and(app(DashboardOrdersQuery::class)->staleBefore()->equalTo(now()->subHours(2)))->toBeTrue();
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
