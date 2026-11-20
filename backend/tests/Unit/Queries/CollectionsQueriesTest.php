<?php

declare(strict_types=1);

use App\Modules\Collections\Data\CollectionFiltersData;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Collections\Queries\CollectionFlowQuery;
use App\Modules\Collections\Queries\CollectionsQuery;
use App\Modules\Collections\Queries\CustomerCreditsQuery;
use App\Modules\Collections\Queries\CustomerStatementQuery;
use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Collections\Queries\OverdueQuery;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
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

test('BR-R04 refunds are taken off the collected amount, a voided refund no longer', function (): void {
    $order = ($this->order)('500.00');
    $return = OrderReturn::factory()->for($order)->create();
    CashCollection::factory()->for($order)->create(['amount' => '500.00']);
    CashCollection::factory()->for($order)->refund($return->id)->create(['amount' => '120.00']);
    CashCollection::factory()->for($order)->refund($return->id)->voided()->create(['amount' => '80.00']);

    expect(app(CollectedAmountQuery::class)->forOrder($order->id))->toBe('380.00');
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

test('D59 BR-R05 what customers owe is net of returns', function (): void {
    $order = ($this->order)('500.00', '200.00');
    $order->forceFill(['returned_total' => '120.00'])->save();

    expect(app(OutstandingByCustomerQuery::class)->forCustomers([$this->customer->id]))->toEqual([$this->customer->id => '180.00'])
        ->and(app(OutstandingByCustomerQuery::class)->total())->toBe('180.00')
        ->and(app(OutstandingByCustomerQuery::class)->builder()->sole()->total)->toBe('380.00');
});

test('D59 D85 what customers owe is the order total after its discount', function (): void {
    Order::factory()->delivered()->create([
        'customer_id' => $this->customer->id, 'items_total' => '540.00', 'discount_type' => 'amount', 'discount_value' => '40', 'discount_amount' => '40.00',
        'discount_reason' => 'عميل دائم', 'order_total' => '500.00', 'collected_amount' => '100.00', 'collection_status' => CollectionStatus::PartiallyCollected,
    ]);

    expect(app(OutstandingByCustomerQuery::class)->forCustomers([$this->customer->id]))->toEqual([$this->customer->id => '400.00'])
        ->and(app(OutstandingByCustomerQuery::class)->total())->toBe('400.00');
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

test('D59 the statement summary counts every open or delivered order but owes only on delivered ones', function (): void {
    ($this->order)('500.00', '200.00');
    ($this->order)('900.00', '100.00', 'confirmed');
    ($this->order)('300.00', state: 'cancelled');

    expect(app(CustomerStatementQuery::class)->summary($this->customer))->toMatchArray([
        'orders_count' => 2, 'total' => '1400.00', 'collected' => '300.00', 'outstanding' => '300.00',
    ]);
});

test('D59 BR-R05 the statement summary is net of returns', function (): void {
    ($this->order)('500.00', '200.00')->forceFill(['returned_total' => '100.00'])->save();

    expect(app(CustomerStatementQuery::class)->summary($this->customer))->toMatchArray([
        'orders_count' => 1, 'total' => '400.00', 'collected' => '200.00', 'outstanding' => '200.00',
    ]);
});

test('a customer without orders has an empty statement summary', function (): void {
    expect(app(CustomerStatementQuery::class)->summary($this->customer))->toBe([
        'orders_count' => 0, 'total' => '0.00', 'collected' => '0.00', 'outstanding' => '0.00',
        'credit_limit' => null, 'payment_terms_days' => null, 'available_credit' => null, 'credit_balance' => '0.00', 'overdue' => '0.00',
    ]);
});

test('BR-C05 BR-C06 BR-C07 the statement summary carries the credit picture: limit, terms, what is left under the limit, unused credit, overdue', function (): void {
    $this->freezeSecond();
    $this->customer->forceFill(['credit_limit' => '2000.00', 'payment_terms_days' => 30])->save();
    (($this->order)('500.00'))->forceFill(['due_at' => now()->subDay()])->save();
    (($this->order)('300.00'))->forceFill(['due_at' => now()->addDays(10)])->save();
    ($this->order)('400.00', state: 'confirmed');
    CustomerCredit::factory()->for($this->customer)->create(['amount' => '150.00', 'remaining' => '100.00']);

    expect(app(CustomerStatementQuery::class)->summary($this->customer->refresh()))->toMatchArray([
        'credit_limit' => '2000.00', 'payment_terms_days' => 30, 'available_credit' => '900.00', 'credit_balance' => '100.00', 'overdue' => '500.00',
    ]);
});

test('BR-C06 overdue counts delivered orders past their due date with a net balance left, per customer and per store', function (): void {
    $this->freezeSecond();
    $other = $this->makeCustomer(['phone' => '+201000000777']);
    ($this->order)('500.00', '100.00')->forceFill(['due_at' => now()->subSecond()])->save();
    ($this->order)('300.00')->forceFill(['due_at' => now()])->save();
    ($this->order)('200.00', '200.00')->forceFill(['due_at' => now()->subDay()])->save();
    ($this->order)('250.00', customerId: $other->id)->forceFill(['due_at' => null, 'delivered_at' => now()->subDay(), 'returned_total' => '50.00'])->save();
    ($this->order)('999.00', state: 'confirmed');

    expect(app(OverdueQuery::class)->total($this->customer))->toBe('400.00')
        ->and(app(OverdueQuery::class)->total())->toBe('600.00')
        ->and(app(OverdueQuery::class)->customersCount())->toBe(2);
});

test('BR-C06 nothing overdue totals zero', function (): void {
    expect(app(OverdueQuery::class)->total())->toBe('0.00')
        ->and(app(OverdueQuery::class)->customersCount())->toBe(0);
});

test('BR-C07 a customer\'s credits are listed newest first, voided included, and found by id', function (): void {
    $old = CustomerCredit::factory()->for($this->customer)->voided()->create();
    $new = CustomerCredit::factory()->for($this->customer)->create();
    CustomerCredit::factory()->create();

    expect(app(CustomerCreditsQuery::class)->of($this->customer)->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and(app(CustomerCreditsQuery::class)->find($new->id)->is($new))->toBeTrue();
});

test('V2 Task 16 the collection flow lists the live collections of the range with their kind, method and customer group', function (): void {
    $group = App\Modules\Customers\Models\CustomerGroup::factory()->create();
    $customer = $this->makeCustomer(['customer_group_id' => $group->id]);
    $order = ($this->order)('300.00', '0.00', 'delivered', $customer->id);
    CashCollection::factory()->for($order)->create(['amount' => '100.00', 'collected_at' => '2026-10-09 10:00:00']);
    CashCollection::factory()->for($order)->voided()->create(['collected_at' => '2026-10-09 11:00:00']);
    CashCollection::factory()->for($order)->create(['collected_at' => '2026-10-11 10:00:00']);
    $this->inTenant(fn () => CashCollection::factory()->create(['collected_at' => '2026-10-09 10:00:00']), $this->otherTenant());

    $rows = app(CollectionFlowQuery::class)->between(CarbonImmutable::parse('2026-10-09 00:00:00'), CarbonImmutable::parse('2026-10-10 23:59:59'))->get();

    expect($rows)->toHaveCount(1)
        ->and((array) $rows->sole())->toEqual(['at' => '2026-10-09 10:00:00', 'kind' => 'payment', 'method' => 'cash', 'amount' => '100.00', 'customer_group_id' => $group->id]);
});
