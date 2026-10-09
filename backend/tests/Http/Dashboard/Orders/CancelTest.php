<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Enums\OrderStatus;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 6]);
    $this->cancel = fn (array $body = ['reason' => 'نفاد الكمية'], array $headers = [], ?int $number = null) => $this->tenantJson('POST', '/api/dashboard/orders/'.($number ?? $this->order->number).'/cancel', $body, headers: $headers);
    $this->at = fn (OrderStatus $status) => $this->inTenant(fn () => $this->order->forceFill(['status' => $status])->save());
});

test('BR-O02 BR-INV04 the merchant cancels an order before delivery: reason, history, released stock, audit', function (OrderStatus $status): void {
    ($this->at)($status);

    ($this->cancel)()
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'نفاد الكمية')
        ->assertJsonPath('data.cancelled_by', 'tenant_user')
        ->assertJsonPath('data.history.1.note', 'نفاد الكمية');

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'reserved' => 0]);
    expect(AuditLog::withoutTenantScope()->where('action', 'order.cancelled')->sole()->actor_id)->toBe($this->owner->id);
})->with(['pending' => [OrderStatus::Pending], 'confirmed' => [OrderStatus::Confirmed], 'out for delivery' => [OrderStatus::OutForDelivery]]);

test('BR-N02 a merchant cancellation does not notify the merchant', function (): void {
    ($this->cancel)()->assertOk();

    $this->assertDatabaseMissing('notifications', ['type' => 'customer_cancelled']);
});

test('BR-O01 a delivered or cancelled order cannot be cancelled', function (OrderStatus $status): void {
    ($this->at)($status);

    ($this->cancel)()->assertConflict()->assertJsonPath('error.code', 'invalid_transition');
})->with(['delivered' => [OrderStatus::Delivered], 'cancelled' => [OrderStatus::Cancelled]]);

test('BR-O03 D7 an order with collections answers 409 has_collections', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['collected_amount' => '200.00'])->save());

    ($this->cancel)()
        ->assertConflict()
        ->assertJsonPath('error.code', 'has_collections')
        ->assertJsonPath('error.details.collected_amount', '200.00');

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'status' => 'pending']);
});

test('BR-O02 the reason is required', function (array $body): void {
    ($this->cancel)($body)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['reason']]]);
})->with(['missing' => [[]], 'blank' => [['reason' => '']], 'too long' => [['reason' => str_repeat('س', 301)]]]);

test('D55 the same Idempotency-Key twice cancels once', function (): void {
    ($this->cancel)(headers: ['Idempotency-Key' => 'cancel-1'])->assertOk();

    ($this->cancel)(headers: ['Idempotency-Key' => 'cancel-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(AuditLog::withoutTenantScope()->where('action', 'order.cancelled')->count())->toBe(1);
});

test('BR-T04 an order number of another tenant is 404 and unchanged there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->cancel)(number: $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'status' => 'pending']);
});
