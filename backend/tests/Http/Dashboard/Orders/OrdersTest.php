<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\TenantSetting;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->sku = $this->orderableSku();
});

test('the merchant lists every order of the store, newest first, with customer and staleness', function (): void {
    $first = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->travel(1)->minutes();
    $second = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 6]);

    $this->tenantJson('GET', '/api/dashboard/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', $second->number)
        ->assertJsonPath('data.1.number', $first->number)
        ->assertJsonPath('data.1.customer', ['id' => $this->customer->id, 'name' => 'مكتبة الأمل', 'phone' => '+201012345678'])
        ->assertJsonPath('data.1.is_stale', false)
        ->assertJsonPath('meta.total', 2)
        ->assertExactJsonStructure(['data' => ['*' => [
            'id', 'number', 'status', 'status_label', 'placed_at', 'items_total', 'order_total', 'collection_status', 'collected_amount',
            'delivery', 'customer_note', 'cancel_reason', 'cancelled_by', 'customer', 'confirmed_at', 'delivered_at', 'cancelled_at', 'is_stale',
        ]], 'links', 'meta']);
});

test('BR-INV07 D10 ?stale=1 lists pending orders older than stale_pending_hours, flagged', function (): void {
    $stale = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => $stale->forceFill(['placed_at' => now()->subHours(25)])->save());
    $this->placeOrder($this->customer, [$this->sku->id => 5]);

    $this->tenantJson('GET', '/api/dashboard/orders?stale=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $stale->number)
        ->assertJsonPath('data.0.is_stale', true);
});

test('BR-INV07 the stale threshold is the tenant setting', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['stale_pending_hours' => 1]);
    $order = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => $order->forceFill(['placed_at' => now()->subHours(2)])->save());

    $this->tenantJson('GET', '/api/dashboard/orders?stale=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_stale', true);
});

test('the list filters by status, collection status, customer, days and search', function (string $query): void {
    $match = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => $match->forceFill(['status' => OrderStatus::Confirmed, 'placed_at' => '2026-10-05 10:00:00'])->save());
    $other = $this->placeOrder($this->makeCustomer(['name' => 'محمود']), [$this->sku->id => 5]);
    $this->inTenant(fn () => $other->forceFill(['placed_at' => '2026-09-01 10:00:00', 'collection_status' => 'partially_collected'])->save());

    $this->tenantJson('GET', '/api/dashboard/orders?'.$query)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $match->number);
})->with([
    'status' => ['status=confirmed'],
    'collection status' => ['collection_status=unpaid'],
    'days' => ['from=2026-10-01&to=2026-10-09'],
    'search by name' => ['q='.urlencode('الأمل')],
    'search by phone' => ['q=01012345678'],
]);

test('the list filters by customer id', function (): void {
    $match = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5]);

    $this->tenantJson('GET', "/api/dashboard/orders?customer_id={$this->customer->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $match->number);
});

test('the list sorts by order total', function (): void {
    $small = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $big = $this->placeOrder($this->customer, [$this->sku->id => 12]);

    $this->tenantJson('GET', '/api/dashboard/orders?sort=-order_total')
        ->assertJsonPath('data.0.number', $big->number)
        ->assertJsonPath('data.1.number', $small->number);
});

test('the list validates its filters', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/orders?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown status' => ['status=lost', 'status'],
    'unknown collection status' => ['collection_status=owed', 'collection_status'],
    'customer not a number' => ['customer_id=x', 'customer_id'],
    'bad from date' => ['from=09-10-2026', 'from'],
    'to before from' => ['from=2026-10-09&to=2026-10-01', 'to'],
    'search too long' => ['q='.str_repeat('a', 101), 'q'],
    'stale not boolean' => ['stale=maybe', 'stale'],
    'unknown sort' => ['sort=customer_id', 'sort'],
]);

test('BR-T04 the list never shows another tenant\'s orders', function (): void {
    $other = $this->otherTenant();
    $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);

    $this->tenantJson('GET', '/api/dashboard/orders')->assertOk()->assertJsonCount(0, 'data');
});

test('the merchant opens an order with lines, history, customer and collections', function (): void {
    $order = $this->placeOrder($this->customer, [$this->sku->id => 12], 'صباحًا');

    $this->tenantJson('GET', "/api/dashboard/orders/{$order->number}")
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.customer.name', 'مكتبة الأمل')
        ->assertJsonPath('data.customer_note', 'صباحًا')
        ->assertJsonPath('data.items.0.line_total', '1020.00')
        ->assertJsonPath('data.history.0', ['from' => null, 'to' => 'pending', 'at' => $order->placed_at->toIso8601String(), 'by' => 'customer', 'actor_id' => $this->customer->id, 'note' => null])
        ->assertJsonPath('data.collections', []);
});

test('BR-T04 an order number of another tenant is 404 here', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);

    $this->tenantJson('GET', "/api/dashboard/orders/{$theirs->number}")->assertNotFound()->assertJsonPath('error.code', 'not_found');

    expect(Order::withoutTenantScope()->findOrFail($theirs->id)->status)->toBe(OrderStatus::Pending);
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (): void {
    $this->tenantJson('GET', '/api/dashboard/orders', tenant: $this->otherTenant())->assertUnauthorized();
});
