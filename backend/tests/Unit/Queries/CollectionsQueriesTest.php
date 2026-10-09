<?php

declare(strict_types=1);

use App\Modules\Collections\Data\CollectionFiltersData;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Collections\Queries\CollectionsQuery;
use App\Modules\Collections\Queries\CustomerStatementQuery;
use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->order = fn (string $total, string $collected = '0.00', ?string $state = 'delivered', ?int $customerId = null): Order => Order::factory()
        ->{$state}()
        ->create(['customer_id' => $customerId ?? $this->customer->id, 'items_total' => $total, 'order_total' => $total, 'collected_amount' => $collected, 'collection_status' => CollectionStatus::for($collected, $total)]);
});

test('BR-C03 the collected amount of an order sums its collections that are not voided', function (): void {
    $order = ($this->order)('500.00');
    CashCollection::factory()->for($order)->create(['amount' => '120.50']);
    CashCollection::factory()->for($order)->create(['amount' => '79.50']);
    CashCollection::factory()->for($order)->voided()->create(['amount' => '300.00']);
    CashCollection::factory()->create(['amount' => '999.00']);

    expect(app(CollectedAmountQuery::class)->forOrder($order->id))->toBe('200.00');
});

test('BR-C03 an order without collections has collected nothing', function (): void {
    expect(app(CollectedAmountQuery::class)->forOrder(($this->order)('500.00')->id))->toBe('0.00');
});

test('the collections list is newest collected first, voided ones included', function (): void {
    $old = CashCollection::factory()->create(['collected_at' => now()->subDays(2)]);
    $voided = CashCollection::factory()->voided()->create(['collected_at' => now()->subDay()]);
    $new = CashCollection::factory()->create(['collected_at' => now()]);

    expect(app(CollectionsQuery::class)->paginate(new CollectionFiltersData(), 24)->pluck('id')->all())->toBe([$new->id, $voided->id, $old->id]);
});

test('the collections list filters by collected time, customer and order number', function (): void {
    $order = ($this->order)('500.00');
    $inRange = CashCollection::factory()->for($order)->create(['collected_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    CashCollection::factory()->for($order)->create(['collected_at' => CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC')]);
    CashCollection::factory()->create(['collected_at' => CarbonImmutable::parse('2026-10-05 11:00:00', 'UTC')]);
    $query = app(CollectionsQuery::class);
    $from = CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-10-06 00:00:00', 'UTC');

    expect($query->paginate(new CollectionFiltersData($from, $to, $this->customer->id), 24)->pluck('id')->all())->toBe([$inRange->id])
        ->and($query->paginate(new CollectionFiltersData($from, $to, orderNumber: $order->number), 24)->pluck('id')->all())->toBe([$inRange->id]);
});

test('the collections list loads the order and its customer', function (): void {
    CashCollection::factory()->for(($this->order)('500.00'))->create();

    $collection = app(CollectionsQuery::class)->paginate(new CollectionFiltersData(), 24)->first();

    expect($collection?->relationLoaded('order'))->toBeTrue()
        ->and($collection?->order->relationLoaded('customer'))->toBeTrue();
});

test('D59 customers owe the balance of their delivered orders only', function (): void {
    ($this->order)('500.00', '200.00');
    ($this->order)('300.00');
    ($this->order)('400.00', '400.00');
    ($this->order)('900.00', '100.00', 'confirmed');
    $other = $this->makeCustomer(['phone' => '+201000000777']);
    ($this->order)('50.00', customerId: $other->id);

    expect(app(OutstandingByCustomerQuery::class)->forCustomers([$this->customer->id, $other->id]))->toEqual([$this->customer->id => '600.00', $other->id => '50.00']);
});

test('D59 customers who owe nothing are missing from the balances', function (): void {
    ($this->order)('400.00', '400.00');

    expect(app(OutstandingByCustomerQuery::class)->forCustomers([$this->customer->id]))->toBe([])
        ->and(app(OutstandingByCustomerQuery::class)->forCustomers([]))->toBe([]);
});

test('D59 the outstanding rows give each customer orders, total, collected and outstanding', function (): void {
    ($this->order)('500.00', '200.00');
    ($this->order)('300.00');

    $row = app(OutstandingByCustomerQuery::class)->builder()->sole();

    expect([(int) $row->customer_id, (int) $row->orders_count, $row->total, $row->collected, $row->outstanding])
        ->toBe([$this->customer->id, 2, '800.00', '200.00', '600.00']);
});

test('D59 the outstanding total of the tenant', function (): void {
    ($this->order)('500.00', '200.00');
    ($this->order)('300.00', customerId: $this->makeCustomer(['phone' => '+201000000777'])->id);
    $this->inTenant(fn (): Order => Order::factory()->delivered()->create(['items_total' => '70.00', 'order_total' => '70.00']), $this->otherTenant());

    expect(app(OutstandingByCustomerQuery::class)->total())->toBe('600.00');
});

test('D59 nothing outstanding totals zero', function (): void {
    expect(app(OutstandingByCustomerQuery::class)->total())->toBe('0.00');
});

test('D24 the statement lists the customer\'s orders newest first, cancelled ones left out', function (): void {
    $old = ($this->order)('100.00');
    $old->forceFill(['placed_at' => now()->subDay()])->save();
    $new = ($this->order)('200.00', state: 'pending');
    ($this->order)('300.00', state: 'cancelled');
    ($this->order)('400.00', customerId: $this->makeCustomer(['phone' => '+201000000777'])->id);

    expect(app(CustomerStatementQuery::class)->orders($this->customer, 24)->pluck('id')->all())->toBe([$new->id, $old->id]);
});

test('D59 the statement summary counts every open or delivered order but owes only on delivered ones', function (): void {
    ($this->order)('500.00', '200.00');
    ($this->order)('900.00', '100.00', 'confirmed');
    ($this->order)('300.00', state: 'cancelled');

    expect(app(CustomerStatementQuery::class)->summary($this->customer))->toBe([
        'orders_count' => 2, 'total' => '1400.00', 'collected' => '300.00', 'outstanding' => '300.00',
    ]);
});

test('a customer without orders has an empty statement summary', function (): void {
    expect(app(CustomerStatementQuery::class)->summary($this->customer))->toBe(['orders_count' => 0, 'total' => '0.00', 'collected' => '0.00', 'outstanding' => '0.00']);
});
