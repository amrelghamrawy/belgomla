<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Enums\OrderStatus;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer();
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    $this->cancel = fn (array $body = ['reason' => 'طلبت بالخطأ'], ?int $number = null) => $this->tenantJson('POST', '/api/store/orders/'.($number ?? $this->order->number).'/cancel', $body);
});

test('BR-O02 BR-INV04 the customer cancels a pending order: status, reason and released stock', function (): void {
    ($this->cancel)()
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'طلبت بالخطأ')
        ->assertJsonPath('data.cancelled_by', 'customer')
        ->assertJsonPath('data.history.1.to', 'cancelled');

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 400, 'reserved' => 0]);
    $this->assertDatabaseHas('notifications', ['tenant_id' => $this->tenant->id, 'type' => 'customer_cancelled']);
    expect(AuditLog::withoutTenantScope()->where('action', 'order.cancelled')->sole()->actor_id)->toBe($this->customer->id);
});

test('BR-O02 once confirmed the customer gets 409 invalid_transition', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['status' => OrderStatus::Confirmed])->save());

    ($this->cancel)()
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition')
        ->assertJsonPath('error.details', ['from' => 'confirmed', 'to' => 'cancelled']);

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'status' => 'confirmed']);
});

test('BR-O03 an order with collections cannot be cancelled', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['collected_amount' => '50.00'])->save());

    ($this->cancel)()->assertConflict()->assertJsonPath('error.code', 'has_collections');
});

test('BR-O02 the reason is required', function (array $body): void {
    ($this->cancel)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['reason']]]);
})->with(['missing' => [[]], 'too long' => [['reason' => str_repeat('س', 301)]]]);

test('D57 another customer\'s order is 404 and stays pending', function (): void {
    $theirs = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5]);

    ($this->cancel)(number: $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'status' => 'pending']);
});

test('BR-T04 an order number of another tenant is 404 and stays pending there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->cancel)(number: $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'status' => 'pending']);
});
