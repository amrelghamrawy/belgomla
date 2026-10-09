<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Enums\OrderStatus;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 6]);
    $this->transition = fn (array $body, array $headers = [], ?int $number = null) => $this->tenantJson('POST', '/api/dashboard/orders/'.($number ?? $this->order->number).'/transition', $body, headers: $headers);
    $this->at = fn (OrderStatus $status) => $this->inTenant(fn () => $this->order->forceFill(['status' => $status])->save());
});

test('BR-O01 the merchant confirms an order with a note, audited', function (): void {
    ($this->transition)(['to' => 'confirmed', 'note' => 'تم التأكيد'])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.history.1', [
            'from' => 'pending', 'to' => 'confirmed', 'at' => $this->order->refresh()->history->last()?->created_at->toIso8601String(),
            'by' => 'tenant_user', 'actor_id' => $this->owner->id, 'note' => 'تم التأكيد',
        ]);

    expect(AuditLog::withoutTenantScope()->where('action', 'order.status_changed')->sole()->only(['actor_id', 'entity_id']))
        ->toBe(['actor_id' => $this->owner->id, 'entity_id' => $this->order->id]);
});

test('BR-O01 steps may be skipped', function (): void {
    ($this->transition)(['to' => 'out_for_delivery'])->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
});

test('BR-INV05 delivering deducts the stock with a sale movement', function (): void {
    ($this->transition)(['to' => 'delivered'])
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.delivered_at', $this->order->refresh()->delivered_at?->toIso8601String());

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 280, 'reserved' => 0]);
    $this->assertDatabaseHas('inventory_movements', ['order_id' => $this->order->id, 'type' => 'sale', 'quantity' => -120, 'actor_id' => $this->owner->id]);
});

test('BR-O01 an invalid move answers 409 invalid_transition with from and to', function (OrderStatus $from, string $to): void {
    ($this->at)($from);

    ($this->transition)(['to' => $to])
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition')
        ->assertJsonPath('error.details', ['from' => $from->value, 'to' => $to]);

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'status' => $from->value]);
})->with([
    'backwards' => [OrderStatus::Preparing, 'confirmed'],
    'onto itself' => [OrderStatus::Confirmed, 'confirmed'],
    'from delivered' => [OrderStatus::Delivered, 'out_for_delivery'],
    'from cancelled' => [OrderStatus::Cancelled, 'confirmed'],
]);

test('transition validation', function (array $body, string $field): void {
    ($this->transition)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'target missing' => [[], 'to'],
    'cancel goes through its own endpoint' => [['to' => 'cancelled'], 'to'],
    'pending is no target' => [['to' => 'pending'], 'to'],
    'unknown target' => [['to' => 'lost'], 'to'],
    'note too long' => [['to' => 'confirmed', 'note' => str_repeat('ن', 301)], 'note'],
    'collect flag not boolean' => [['to' => 'delivered', 'collect_full_amount' => 'yes'], 'collect_full_amount'],
]);

test('D55 the same Idempotency-Key twice moves the order once', function (): void {
    ($this->transition)(['to' => 'delivered'], ['Idempotency-Key' => 'deliver-1'])->assertOk();

    ($this->transition)(['to' => 'delivered'], ['Idempotency-Key' => 'deliver-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    $this->assertDatabaseCount('inventory_movements', 1);
});

test('BR-T04 an order number of another tenant is 404 and unchanged there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->transition)(['to' => 'confirmed'], number: $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'status' => 'pending']);
});
