<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer();
    $this->order = fn (string $state, string $total, string $collected = '0.00', array $attributes = []): Order => $this->inTenant(fn (): Order => Order::factory()->{$state}()->for($this->customer)->create([
        'items_total' => $total, 'order_total' => $total, 'collected_amount' => $collected, 'collection_status' => CollectionStatus::for($collected, $total), ...$attributes,
    ]));
});

test('D24 the statement lists the customer\'s orders with totals, collected and balance, and a summary', function (): void {
    $delivered = ($this->order)('delivered', '500.00', '200.00', ['placed_at' => now()->subDay()]);
    $deposit = ($this->order)('confirmed', '300.00', '100.00');
    ($this->order)('cancelled', '50.00');

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement")
        ->assertOk()
        ->assertJsonPath('data.0.number', $deposit->number)
        ->assertJsonPath('data.0.balance', '200.00')
        ->assertJsonPath('data.1.number', $delivered->number)
        ->assertJsonPath('data.1.balance', '300.00')
        ->assertJsonPath('customer', ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone])
        ->assertJsonPath('summary', ['orders_count' => 2, 'total' => '800.00', 'collected' => '300.00', 'outstanding' => '300.00'])
        ->assertExactJsonStructure([
            'data' => ['*' => ['number', 'status', 'status_label', 'placed_at', 'delivered_at', 'order_total', 'collected_amount', 'balance', 'collection_status']],
            'links', 'meta', 'customer' => ['id', 'name', 'phone'], 'summary' => ['orders_count', 'total', 'collected', 'outstanding'],
        ]);
});

test('D59 the customer page owes what the statement summary owes', function (): void {
    ($this->order)('delivered', '500.00', '200.00');
    ($this->order)('confirmed', '300.00', '100.00');

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}")->assertJsonPath('data.outstanding_amount', '300.00');
    $this->tenantJson('GET', '/api/dashboard/customers')->assertJsonPath('data.0.outstanding_amount', '300.00');
});

test('the statement pages its orders', function (): void {
    ($this->order)('delivered', '10.00');
    ($this->order)('delivered', '20.00');

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement?per_page=1")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('summary.orders_count', 2);
});

test('the statement page size is validated', function (): void {
    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement?per_page=0")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['per_page']]]);
});

test('BR-T04 a customer of another tenant has no statement here', function (): void {
    $theirs = $this->makeCustomer(tenant: $this->otherTenant());

    $this->tenantJson('GET', "/api/dashboard/customers/{$theirs->id}/statement")->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host, even for a customer that exists there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->makeCustomer(tenant: $other);

    $this->tenantJson('GET', "/api/dashboard/customers/{$theirs->id}/statement", tenant: $other)->assertUnauthorized();
});
