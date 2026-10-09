<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer();
    $this->sku = $this->orderableSku();
});

test('a customer lists their orders, newest first, without lines', function (): void {
    $first = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->travel(1)->minutes();
    $second = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5]);

    $this->tenantJson('GET', '/api/store/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', $second->number)
        ->assertJsonPath('data.1.number', $first->number)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissingPath('data.0.items')
        ->assertExactJsonStructure(['data' => ['*' => ['number', 'status', 'status_label', 'placed_at', 'items_total', 'order_total', 'collection_status', 'collected_amount', 'delivery', 'customer_note', 'cancel_reason', 'cancelled_by']], 'links', 'meta']);
});

test('the list filters by status', function (): void {
    $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $delivered = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => $delivered->forceFill(['status' => OrderStatus::Delivered])->save());

    $this->tenantJson('GET', '/api/store/orders?status=delivered')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $delivered->number);
});

test('the list validates its filters', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/store/orders?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with(['unknown status' => ['status=lost', 'status'], 'per page too large' => ['per_page=101', 'per_page']]);

test('BR-O04 a customer opens an order by its number with lines, history and collection status', function (): void {
    $order = $this->placeOrder($this->customer, [$this->sku->id => 12]);

    $this->tenantJson('GET', "/api/store/orders/{$order->number}")
        ->assertOk()
        ->assertJsonPath('data.number', $order->number)
        ->assertJsonPath('data.collection_status', 'unpaid')
        ->assertJsonPath('data.items.0.quantity', 12)
        ->assertJsonPath('data.items.0.unit_price', '85.00')
        ->assertJsonPath('data.history.0.to', 'pending')
        ->assertJsonPath('data.history.0.by', 'customer');
});

test('D57 another customer\'s order is 404', function (): void {
    $theirs = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5]);

    $this->tenantJson('GET', "/api/store/orders/{$theirs->number}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('BR-T04 an order number of another tenant is 404 here even when it exists there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);

    $this->tenantJson('GET', "/api/store/orders/{$theirs->number}")->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'status' => 'pending']);
});

test('an unknown order number is 404', function (): void {
    $this->tenantJson('GET', '/api/store/orders/999')->assertNotFound();
});

test('Tech §6 the customer session is refused on another store\'s host', function (): void {
    $this->tenantJson('GET', '/api/store/orders', tenant: $this->otherTenant())->assertUnauthorized();
});

test('BR-CU05 D51 a blocked customer cannot see the order list', function (): void {
    $this->customer->forceFill(['status' => App\Modules\Customers\Enums\CustomerStatus::Blocked])->save();

    $this->tenantJson('GET', '/api/store/orders')->assertForbidden()->assertJsonPath('error.code', 'blocked');
});

test('the history follows the merchant\'s changes', function (): void {
    $order = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    $owner = $this->makeOwner();
    $this->inTenant(fn (): Order => app(App\Modules\Orders\Actions\TransitionOrder::class)->handle($order, new App\Modules\Orders\Data\TransitionData(OrderStatus::Confirmed), $owner->id));

    $this->tenantJson('GET', "/api/store/orders/{$order->number}")
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.history.1', ['from' => 'pending', 'to' => 'confirmed', 'at' => $order->refresh()->history->last()?->created_at->toIso8601String(), 'by' => 'tenant_user']);
});
