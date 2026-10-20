<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\ApplyCustomerCredit;
use App\Modules\Collections\Actions\KeepAsCredit;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Actions\VoidCollection;
use App\Modules\Collections\Actions\VoidCustomerCredit;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Exceptions\CannotCollect;
use App\Modules\Collections\Exceptions\CreditUnavailable;
use App\Modules\Collections\Exceptions\ExceedsBalance;
use App\Modules\Collections\Exceptions\ExceedsCredit;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Actions\VoidReturn;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Exceptions\ReturnHasRefund;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;

/*
| BR-C07 with real flows: order 1 (6 cartons at 90.00 = 540.00) delivered and collected in full, then 2 cartons
| (180.00) returned and kept as credit; order 2 (540.00) delivered and unpaid.
*/

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    $this->deliver = function (): Order {
        $order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
        app(TenantContext::class)->set($this->tenant);

        return app(TransitionOrder::class)->handle($order, new TransitionData(OrderStatus::Delivered), $this->owner->id);
    };
    $this->first = ($this->deliver)();
    app(RecordCollection::class)->handle($this->first, new CollectionData('540.00'), $this->owner->id);
    $this->return = app(RecordReturn::class)->handle(
        $this->first,
        new ReturnData('مرتجع', [new ReturnLineData(OrderItem::query()->where('order_id', $this->first->id)->value('id'), 2)], refund: true, refundTo: RefundMethod::Credit),
        $this->owner->id,
    );
    $this->credit = CustomerCredit::query()->sole();
    $this->second = ($this->deliver)();
    AuditLog::query()->delete();

    $this->apply = fn (string $amount, ?Order $order = null, ?CustomerCredit $credit = null): CashCollection => app(ApplyCustomerCredit::class)->handle($order ?? $this->second, $credit ?? $this->credit, $amount, $this->owner->id);
});

test('BR-C07 a return kept as credit creates the credit and a credit refund instead of cash', function (): void {
    expect($this->credit->only(['customer_id', 'source_return_id', 'amount', 'remaining', 'created_by']))->toBe([
        'customer_id' => $this->customer->id, 'source_return_id' => $this->return->id, 'amount' => '180.00', 'remaining' => '180.00', 'created_by' => $this->owner->id,
    ])
        ->and(CashCollection::query()->where('kind', CollectionKind::Refund)->sole()->only(['order_return_id', 'customer_credit_id', 'method', 'amount']))->toBe([
            'order_return_id' => $this->return->id, 'customer_credit_id' => $this->credit->id, 'method' => CollectionMethod::Credit, 'amount' => '180.00',
        ])
        ->and($this->first->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '360.00', 'collection_status' => CollectionStatus::Collected])
        ->and($this->return->refund_method)->toBe(RefundMethod::Credit);
});

test('BR-L keeping as credit is audited as customer_credit.created', function (): void {
    $credit = app(KeepAsCredit::class)->handle($this->first, $this->return->id, '10.00', $this->owner->id);

    expect(AuditLog::query()->where('action', 'customer_credit.created')->sole()->only(['entity_type', 'entity_id', 'new_values']))->toEqual([
        'entity_type' => 'customer_credits', 'entity_id' => $credit->id,
        'new_values' => ['customer_id' => $this->customer->id, 'order_number' => $this->first->number, 'order_return_id' => $this->return->id, 'amount' => '10.00'],
    ]);
});

test('BR-C07 applying credit records a credit payment, lowers what is left and what the order owes', function (): void {
    $collection = ($this->apply)('100.00');

    expect($collection->only(['order_id', 'kind', 'method', 'customer_credit_id', 'amount']))->toBe([
        'order_id' => $this->second->id, 'kind' => CollectionKind::Payment, 'method' => CollectionMethod::Credit, 'customer_credit_id' => $this->credit->id, 'amount' => '100.00',
    ])
        ->and($this->credit->refresh()->remaining)->toBe('80.00')
        ->and($this->second->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '100.00', 'collection_status' => CollectionStatus::PartiallyCollected]);
});

test('BR-L applying credit is audited with the remaining before and after', function (): void {
    $collection = ($this->apply)('30.00');

    expect(AuditLog::query()->sole()->only(['action', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'customer_credit.applied', 'entity_id' => $this->credit->id,
        'old_values' => ['remaining' => '180.00'],
        'new_values' => ['remaining' => '150.00', 'order_number' => $this->second->number, 'collection_id' => $collection->id, 'amount' => '30.00'],
    ]);
});

test('BR-C07 never more than what is left of the credit', function (): void {
    expect(fn (): CashCollection => ($this->apply)('180.01'))->toThrow(function (ExceedsCredit $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'exceeds_credit', ['remaining' => '180.00']]);
    });
});

test('BR-C03 BR-C07 never more than what the order still owes', function (): void {
    app(RecordCollection::class)->handle($this->second, new CollectionData('500.00'), $this->owner->id);

    expect(fn (): CashCollection => ($this->apply)('40.01'))->toThrow(function (ExceedsBalance $e): void {
        expect($e->details)->toBe(['remaining' => '40.00']);
    });
});

test('BR-T04 BR-C07 a credit of another customer is not found', function (): void {
    $theirs = CustomerCredit::factory()->create();

    expect(fn (): CashCollection => ($this->apply)('10.00', credit: $theirs))->toThrow(function (ApiException $e): void {
        expect($e->status)->toBe(404);
    });
});

test('BR-C07 a voided credit cannot be applied', function (): void {
    $voided = CustomerCredit::factory()->for($this->customer)->voided()->create();

    expect(fn (): CashCollection => ($this->apply)('10.00', credit: $voided))->toThrow(function (CreditUnavailable $e): void {
        expect([$e->status, $e->errorCode])->toBe([409, 'credit_voided']);
    });
});

test('BR-C04 credit is not applied to a cancelled order', function (): void {
    $cancelled = Order::factory()->cancelled()->for($this->customer)->create(['items_total' => '100.00', 'order_total' => '100.00']);

    expect(fn (): CashCollection => ($this->apply)('10.00', $cancelled))->toThrow(CannotCollect::class);
});

test('BR-C07 voiding a credit payment gives the amount back to the credit', function (): void {
    $collection = ($this->apply)('100.00');

    app(VoidCollection::class)->handle($collection, 'خطأ', $this->owner->id);

    expect($this->credit->refresh()->remaining)->toBe('180.00')
        ->and($this->second->refresh()->collected_amount)->toBe('0.00');
});

test('BR-C07 a credit is voided with its refund while nothing of it was applied, and the return can then be voided', function (): void {
    $voided = app(VoidCustomerCredit::class)->handle($this->credit, 'خطأ في المرتجع', $this->owner->id);

    expect($voided->voided_at)->not->toBeNull()
        ->and($voided->void_reason)->toBe('خطأ في المرتجع')
        ->and(CashCollection::query()->where('kind', CollectionKind::Refund)->sole()->voided_at)->not->toBeNull()
        ->and($this->first->refresh()->collected_amount)->toBe('540.00');

    app(VoidReturn::class)->handle($this->return, 'خطأ', $this->owner->id);
    expect(OrderReturn::query()->sole()->voided_at)->not->toBeNull();
});

test('BR-C07 a credit partly applied cannot be voided, nor its refund', function (): void {
    ($this->apply)('50.00');

    expect(fn (): CustomerCredit => app(VoidCustomerCredit::class)->handle($this->credit, 'خطأ', $this->owner->id))->toThrow(function (CreditUnavailable $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'credit_applied', ['applied' => '50.00']]);
    });

    expect($this->credit->refresh()->voided_at)->toBeNull()
        ->and(CashCollection::query()->where('kind', CollectionKind::Refund)->sole()->voided_at)->toBeNull();
});

test('BR-C07 a credit is voided only once', function (): void {
    app(VoidCustomerCredit::class)->handle($this->credit, 'خطأ', $this->owner->id);

    expect(fn (): CustomerCredit => app(VoidCustomerCredit::class)->handle($this->credit->refresh(), 'مرة أخرى', $this->owner->id))->toThrow(function (CreditUnavailable $e): void {
        expect($e->errorCode)->toBe('credit_voided');
    });
});

test('BR-R06 a return kept as credit is voided only after its credit refund', function (): void {
    expect(fn (): OrderReturn => app(VoidReturn::class)->handle($this->return, 'خطأ', $this->owner->id))->toThrow(ReturnHasRefund::class);
});

test('D88 credit is not applied to a pre-order', function (): void {
    $preorder = Order::factory()->preorder()->for($this->customer)->create(['items_total' => '100.00', 'order_total' => '100.00']);

    expect(fn (): CashCollection => ($this->apply)('10.00', $preorder))->toThrow(function (CannotCollect $e): void {
        expect($e->errorCode)->toBe('is_preorder');
    });
});
