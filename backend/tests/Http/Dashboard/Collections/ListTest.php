<?php

declare(strict_types=1);

use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer();
    $this->order = $this->inTenant(fn (): Order => Order::factory()->delivered()->for($this->customer)->create(['items_total' => '500.00', 'order_total' => '500.00']));
    $this->collect = fn (array $attributes = [], ?Order $order = null): CashCollection => $this->inTenant(fn (): CashCollection => CashCollection::factory()->for($order ?? $this->order)->create($attributes));
});

test('BR-C02 the collections list shows every collection with its order and customer, newest first', function (): void {
    $old = ($this->collect)(['collected_at' => now()->subDay()]);
    $new = ($this->collect)(['collected_at' => now()]);

    $this->tenantJson('GET', '/api/dashboard/collections')
        ->assertOk()
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.0.customer', ['id' => $this->customer->id, 'name' => $this->customer->name])
        ->assertJsonPath('meta.total', 2)
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'order' => ['id', 'number', 'order_total', 'collected_amount', 'collection_status'], 'customer' => ['id', 'name'], 'amount', 'method', 'method_label', 'collected_at', 'recorded_by', 'note', 'is_voided', 'voided_at', 'void_reason', 'voided_by', 'created_at']], 'links', 'meta']);
});

test('D60 the from/to filters are cairo business days', function (): void {
    ($this->collect)(['collected_at' => CarbonImmutable::parse('2026-10-04 20:59:59', 'UTC')]);
    $inside = ($this->collect)(['collected_at' => CarbonImmutable::parse('2026-10-04 21:00:00', 'UTC')]);

    $this->tenantJson('GET', '/api/dashboard/collections?from=2026-10-05&to=2026-10-05')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inside->id);
});

test('the list filters by customer and by order number', function (): void {
    $mine = ($this->collect)();
    $otherOrder = $this->inTenant(fn (): Order => Order::factory()->delivered()->create(['items_total' => '90.00', 'order_total' => '90.00']));
    ($this->collect)([], $otherOrder);

    $this->tenantJson('GET', "/api/dashboard/collections?customer_id={$this->customer->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->tenantJson('GET', "/api/dashboard/collections?order={$this->order->number}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
});

test('every list filter is validated', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/collections?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'from not a day' => ['from=10/10/2026', 'from'],
    'to before from' => ['from=2026-10-05&to=2026-10-04', 'to'],
    'customer not a number' => ['customer_id=x', 'customer_id'],
    'order not a number' => ['order=x', 'order'],
    'per page above the maximum' => ['per_page=101', 'per_page'],
]);

test('BR-T03 collections of another tenant never appear', function (): void {
    $this->inTenant(fn (): CashCollection => CashCollection::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/collections')->assertOk()->assertJsonCount(0, 'data');
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (): void {
    $this->tenantJson('GET', '/api/dashboard/collections', tenant: $this->otherTenant())->assertUnauthorized();
});
