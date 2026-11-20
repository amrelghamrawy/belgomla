<?php

declare(strict_types=1);

use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

/*
| BR-C08: the statement is a ledger. 2026-10-10 12:00 UTC is 15:00 in Cairo (D60).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['credit_limit' => '5000.00', 'payment_terms_days' => 30]);
    $this->order = fn (string $state, string $total, array $attributes = []): Order => $this->inTenant(fn (): Order => Order::factory()->{$state}()->for($this->customer)->create([
        'items_total' => $total, 'order_total' => $total, ...$attributes,
    ]));
    $this->collect = fn (Order $order, string $amount, string $at): CashCollection => $this->inTenant(fn (): CashCollection => app(RecordCollection::class)->handle($order, new CollectionData($amount, CarbonImmutable::parse($at, 'UTC')), $this->owner->id));
});

test('BR-C08 the statement is a dated ledger with a running balance, the customer and the summary', function (): void {
    $order = ($this->order)('delivered', '500.00', ['delivered_at' => CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'), 'due_at' => CarbonImmutable::parse('2026-10-31 09:00:00', 'UTC')]);
    ($this->collect)($order, '200.00', '2026-10-05 09:00:00');
    ($this->order)('cancelled', '50.00');

    $response = $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement")
        ->assertOk()
        ->assertJsonPath('data.0', [
            'date' => '2026-10-01T09:00:00+00:00', 'type' => 'order', 'type_label' => 'طلب مسلَّم', 'order_number' => $order->number,
            'return_number' => null, 'debit' => '500.00', 'credit' => '0.00', 'balance' => '500.00',
            'method' => null, 'method_label' => null, 'reference' => null,
        ])
        ->assertJsonPath('data.1.method', 'cash')
        ->assertJsonPath('data.1.type', 'payment')
        ->assertJsonPath('data.1.credit', '200.00')
        ->assertJsonPath('data.1.balance', '300.00')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.opening_balance', '0.00')
        ->assertJsonPath('meta.closing_balance', '300.00')
        ->assertJsonPath('customer', ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone])
        ->assertJsonPath('summary', [
            'orders_count' => 1, 'total' => '500.00', 'collected' => '200.00', 'outstanding' => '300.00',
            'credit_limit' => '5000.00', 'payment_terms_days' => 30, 'available_credit' => '4700.00', 'credit_balance' => '0.00', 'overdue' => '0.00',
        ]);

    expectDocumentedShape($response->json('data.0'), 'LedgerRow');
    expectDocumentedShape($response->json('meta'), 'LedgerMeta');
    expectDocumentedShape($response->json('summary'), 'StatementSummary');
});

test('BR-C10 a statement payment row says how it came in and its reference', function (): void {
    $order = ($this->order)('delivered', '500.00', ['delivered_at' => CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC')]);
    $this->inTenant(fn (): CashCollection => app(RecordCollection::class)->handle($order, new CollectionData('300.00', CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'), method: App\Modules\Collections\Enums\CollectionMethod::Wallet, reference: 'VC-0193'), $this->owner->id));

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement")
        ->assertOk()
        ->assertJsonPath('data.1.type', 'payment')
        ->assertJsonPath('data.1.method', 'wallet')
        ->assertJsonPath('data.1.method_label', 'محفظة إلكترونية')
        ->assertJsonPath('data.1.reference', 'VC-0193');
});

test('D60 BR-C08 a date range starts from the opening balance of everything before it, in cairo days', function (): void {
    $first = ($this->order)('delivered', '500.00', ['delivered_at' => CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC')]);
    ($this->collect)($first, '100.00', '2026-10-04 20:59:59');
    ($this->collect)($first, '50.00', '2026-10-04 21:00:00');

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement?from=2026-10-05&to=2026-10-10")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.credit', '50.00')
        ->assertJsonPath('data.0.balance', '350.00')
        ->assertJsonPath('meta.opening_balance', '400.00')
        ->assertJsonPath('meta.closing_balance', '350.00');
});

test('the statement pages its rows and keeps the running balance across pages', function (): void {
    ($this->order)('delivered', '10.00', ['delivered_at' => now()->subDays(2)]);
    ($this->order)('delivered', '20.00', ['delivered_at' => now()->subDay()]);

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement?per_page=1&page=2")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.balance', '30.00')
        ->assertJsonPath('meta.total', 2);
});

test('the statement filters are validated', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement?{$query}")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'page size' => ['per_page=0', 'per_page'],
    'from not a day' => ['from=05-10-2026', 'from'],
    'to before from' => ['from=2026-10-05&to=2026-10-04', 'to'],
]);

test('D59 the customer page owes what the statement summary owes', function (): void {
    ($this->order)('delivered', '500.00', ['collected_amount' => '200.00', 'collection_status' => CollectionStatus::PartiallyCollected]);
    ($this->order)('confirmed', '300.00', ['collected_amount' => '100.00', 'collection_status' => CollectionStatus::PartiallyCollected]);

    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}")->assertJsonPath('data.outstanding_amount', '300.00');
    $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/statement")->assertJsonPath('summary.outstanding', '300.00');
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
