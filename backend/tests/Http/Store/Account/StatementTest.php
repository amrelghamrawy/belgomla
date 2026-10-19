<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->customer = $this->makeCustomer(['credit_limit' => '1000.00']);
    $this->delivered = fn (string $total, ?int $customerId = null): Order => $this->inTenant(fn (): Order => Order::factory()->delivered()->create([
        'customer_id' => $customerId ?? $this->customer->id, 'items_total' => $total, 'order_total' => $total, 'delivered_at' => now()->subDays(3), 'due_at' => now()->addDays(10),
    ]));
});

test('BR-C08 a customer reads their own ledger with what they owe, their credit and what is overdue', function (): void {
    $order = ($this->delivered)('400.00');
    ($this->delivered)('999.00', $this->makeCustomer(['phone' => '+201000000777'])->id);
    $this->actingAsCustomer($this->customer);

    $response = $this->tenantJson('GET', '/api/store/account/statement')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.order_number', $order->number)
        ->assertJsonPath('data.0.balance', '400.00')
        ->assertJsonPath('meta.closing_balance', '400.00')
        ->assertJsonPath('summary', ['outstanding' => '400.00', 'credit_limit' => '1000.00', 'available_credit' => '600.00', 'credit_balance' => '0.00', 'overdue' => '0.00'])
        ->assertJsonMissingPath('customer');

    expectDocumentedShape($response->json('summary'), 'StoreStatementSummary');
    expectDocumentedShape($response->json('meta'), 'LedgerMeta');
});

test('D60 the customer narrows their statement to a range of days', function (): void {
    ($this->delivered)('400.00');
    $this->actingAsCustomer($this->customer);

    $this->tenantJson('GET', '/api/store/account/statement?from=2026-10-09&to=2026-10-10')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.opening_balance', '400.00');
});

test('the statement range is validated', function (): void {
    $this->actingAsCustomer($this->customer);

    $this->tenantJson('GET', '/api/store/account/statement?from=2026-10-09&to=2026-10-01')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['to']]]);
});

test('a guest has no statement', function (): void {
    $this->tenantJson('GET', '/api/store/account/statement')->assertUnauthorized();
});

test('BR-CU05 a blocked customer can still read what they owe', function (): void {
    $blocked = $this->makeCustomer(['phone' => '+201000000888', 'status' => App\Modules\Customers\Enums\CustomerStatus::Blocked]);
    $this->actingAsCustomer($blocked);

    $this->tenantJson('GET', '/api/store/account/statement')->assertOk();
});

test('Tech §6 a customer session of this store is refused on another store\'s host', function (): void {
    $this->actingAsCustomer($this->customer);

    $this->tenantJson('GET', '/api/store/account/statement', tenant: $this->otherTenant())->assertUnauthorized();
});
