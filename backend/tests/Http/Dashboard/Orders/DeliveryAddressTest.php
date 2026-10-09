<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Enums\OrderStatus;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->orderableSku()->id => 5]);
    $this->body = ['label' => 'المخزن', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => '5 شارع التحرير', 'landmark' => null, 'name' => 'أحمد', 'phone' => '01112223334'];
    $this->update = fn (array $body, ?int $number = null) => $this->tenantJson('PUT', '/api/dashboard/orders/'.($number ?? $this->order->number).'/delivery-address', $body);
});

test('BR-O10 D9 the merchant edits the delivery snapshot, audited', function (): void {
    $response = ($this->update)($this->body)->assertOk();

    expect($response->json('data.delivery'))->toEqual([
        'name' => 'أحمد',
        'phone' => '+201112223334',
        'address' => ['label' => 'المخزن', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => '5 شارع التحرير', 'landmark' => null],
    ]);

    expect(AuditLog::withoutTenantScope()->where('action', 'order.delivery_address_changed')->sole())
        ->actor_id->toBe($this->owner->id)
        ->entity_id->toBe($this->order->id);
});

test('D9 from out for delivery on the address is locked', function (OrderStatus $status): void {
    $this->inTenant(fn () => $this->order->forceFill(['status' => $status])->save());

    ($this->update)($this->body)->assertConflict()->assertJsonPath('error.code', 'address_locked');

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'delivery_name' => $this->order->delivery_name]);
})->with(['out for delivery' => [OrderStatus::OutForDelivery], 'delivered' => [OrderStatus::Delivered], 'cancelled' => [OrderStatus::Cancelled]]);

test('delivery address validation', function (array $override, string $field): void {
    ($this->update)([...$this->body, ...$override])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'governorate missing' => [['governorate' => null], 'governorate'],
    'city missing' => [['city' => ''], 'city'],
    'address line missing' => [['address_line' => null], 'address_line'],
    'address line too long' => [['address_line' => str_repeat('ش', 301)], 'address_line'],
    'label too long' => [['label' => str_repeat('م', 51)], 'label'],
    'landmark too long' => [['landmark' => str_repeat('م', 201)], 'landmark'],
    'name too long' => [['name' => str_repeat('أ', 151)], 'name'],
    'phone not egyptian' => [['phone' => '12345'], 'phone'],
]);

test('BR-T04 an order number of another tenant is 404 and unchanged there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->update)($this->body, $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'delivery_name' => $theirs->delivery_name]);
});
