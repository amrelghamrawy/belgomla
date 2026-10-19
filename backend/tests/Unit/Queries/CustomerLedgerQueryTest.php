<?php

declare(strict_types=1);

use App\Modules\Collections\Actions\ApplyCustomerCredit;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Actions\VoidCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Queries\CustomerLedgerQuery;
use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\TenantContext;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;

/*
| BR-C08 with real flows. Orders of 6 cartons at 90.00 = 540.00; time moves forward a day between steps so the rows
| keep their order. 2026-10-01 is in Cairo summer time (UTC+3, D60).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    app(TenantContext::class)->set($this->tenant);

    $this->delivered = function (): Order {
        $order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
        app(TenantContext::class)->set($this->tenant);

        return app(TransitionOrder::class)->handle($order, new TransitionData(OrderStatus::Delivered), $this->owner->id);
    };
    $this->collect = fn (Order $order, string $amount): CashCollection => app(RecordCollection::class)->handle($order, new CollectionData($amount), $this->owner->id);
    $this->return = fn (Order $order, int $quantity, bool $refund = false, RefundMethod $to = RefundMethod::Cash) => app(RecordReturn::class)->handle(
        $order,
        new ReturnData('مرتجع', [new ReturnLineData(OrderItem::query()->where('order_id', $order->id)->value('id'), $quantity)], refund: $refund, refundTo: $to),
        $this->owner->id,
    );
    $this->ledger = fn (?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array => array_map(
        fn (object $row): array => [$row->type, (string) $row->debit, (string) $row->credit, (string) $row->balance],
        app(CustomerLedgerQuery::class)->paginate($this->customer, $from, $to, 100)->items(),
    );
    $this->tomorrow = fn () => $this->travel(1)->days();
});

test('BR-C08 a delivered order is a debit, a payment a credit, with the running balance', function (): void {
    $order = ($this->delivered)();
    ($this->tomorrow)();
    ($this->collect)($order, '200.00');

    expect(($this->ledger)())->toBe([
        ['order', '540.00', '0.00', '540.00'],
        ['payment', '0.00', '200.00', '340.00'],
    ]);
});

test('BR-C08 BR-R04 a return is a credit and its cash refund a debit', function (): void {
    $order = ($this->delivered)();
    ($this->tomorrow)();
    ($this->collect)($order, '540.00');
    ($this->tomorrow)();
    ($this->return)($order, 2, refund: true);

    expect(($this->ledger)())->toBe([
        ['order', '540.00', '0.00', '540.00'],
        ['payment', '0.00', '540.00', '0.00'],
        ['return', '0.00', '180.00', '-180.00'],
        ['refund', '180.00', '0.00', '0.00'],
    ]);
});

test('BR-C08 BR-C07 a return kept as credit is a refund to credit, the credit an info line, its use a credit applied', function (): void {
    $first = ($this->delivered)();
    ($this->tomorrow)();
    ($this->collect)($first, '540.00');
    ($this->tomorrow)();
    ($this->return)($first, 2, refund: true, to: RefundMethod::Credit);
    ($this->tomorrow)();
    $second = ($this->delivered)();
    ($this->tomorrow)();
    app(ApplyCustomerCredit::class)->handle($second, CustomerCredit::query()->sole(), '180.00', $this->owner->id);

    expect(($this->ledger)())->toBe([
        ['order', '540.00', '0.00', '540.00'],
        ['payment', '0.00', '540.00', '0.00'],
        ['return', '0.00', '180.00', '-180.00'],
        ['refund_to_credit', '180.00', '0.00', '0.00'],
        ['credit_created', '0.00', '0.00', '0.00'],
        ['order', '540.00', '0.00', '540.00'],
        ['credit_applied', '0.00', '180.00', '360.00'],
    ]);
});

test('BR-C08 voided rows are left out', function (): void {
    $order = ($this->delivered)();
    ($this->tomorrow)();
    app(VoidCollection::class)->handle(($this->collect)($order, '100.00'), 'خطأ', $this->owner->id);

    expect(array_column(($this->ledger)(), 0))->toBe(['order']);
});

test('BR-C08 orders that are not delivered and other customers carry no row', function (): void {
    $this->placeOrder($this->customer, [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    $other = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    app(TransitionOrder::class)->handle($other, new TransitionData(OrderStatus::Delivered), $this->owner->id);

    expect(($this->ledger)())->toBe([]);
});

test('D60 the range is cut on cairo days and the opening balance counts everything before it', function (): void {
    $order = ($this->delivered)();
    $this->travelTo(CarbonImmutable::parse('2026-10-04 20:59:59', 'UTC'));
    ($this->collect)($order, '100.00');
    $this->travelTo(CarbonImmutable::parse('2026-10-04 21:00:00', 'UTC'));
    ($this->collect)($order, '40.00');
    $from = BusinessCalendar::dayStart('2026-10-05', 'Africa/Cairo');

    expect(($this->ledger)($from))->toBe([['payment', '0.00', '40.00', '400.00']])
        ->and(app(CustomerLedgerQuery::class)->openingBalance($this->customer, $from))->toBe('440.00')
        ->and(app(CustomerLedgerQuery::class)->openingBalance($this->customer, null))->toBe('0.00')
        ->and(app(CustomerLedgerQuery::class)->closingBalance($this->customer, BusinessCalendar::dayEnd('2026-10-04', 'Africa/Cairo')))->toBe('440.00')
        ->and(app(CustomerLedgerQuery::class)->closingBalance($this->customer, null))->toBe('400.00');
});

test('D59 BR-C08 with no credit left, the final balance is what the customer owes', function (): void {
    $first = ($this->delivered)();
    ($this->tomorrow)();
    ($this->collect)($first, '300.00');
    ($this->tomorrow)();
    ($this->return)($first, 1);
    ($this->tomorrow)();
    ($this->delivered)();

    expect(app(CustomerLedgerQuery::class)->closingBalance($this->customer, null))
        ->toBe(app(OutstandingByCustomerQuery::class)->forCustomers([$this->customer->id])[$this->customer->id]);
});

test('BR-T03 the ledger of a customer of another tenant is empty here', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->makeCustomer([], $other);
    $this->inTenant(fn (): Order => Order::factory()->delivered()->for($theirs)->create(['items_total' => '70.00', 'order_total' => '70.00']), $other);
    app(TenantContext::class)->set($this->tenant);

    expect(app(CustomerLedgerQuery::class)->paginate($theirs, null, null, 100)->total())->toBe(0);
});
