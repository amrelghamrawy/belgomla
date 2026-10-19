<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

/*
| BR-C07 over HTTP: order 1 (540.00) collected in full, 2 cartons (180.00) returned and kept as credit; order 2
| (540.00) delivered and unpaid.
*/

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    $this->deliver = function (): Order {
        $order = $this->placeOrder($this->customer, [$this->sku->id => 6]);

        return $this->inTenant(fn (): Order => app(TransitionOrder::class)->handle($order, new TransitionData(OrderStatus::Delivered), $this->owner->id));
    };
    $first = ($this->deliver)();
    $this->inTenant(function () use ($first): void {
        app(RecordCollection::class)->handle($first, new CollectionData('540.00'), $this->owner->id);
        app(RecordReturn::class)->handle($first, new ReturnData('مرتجع', [new ReturnLineData(OrderItem::query()->where('order_id', $first->id)->value('id'), 2)], refund: true, refundTo: RefundMethod::Credit), $this->owner->id);
    });
    $this->credit = $this->inTenant(fn (): CustomerCredit => CustomerCredit::query()->sole());
    $this->second = ($this->deliver)();

    $this->apply = fn (array $body, ?int $number = null, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/orders/'.($number ?? $this->second->number).'/apply-credit', $body, headers: $headers);
    $this->void = fn (?int $id = null, array $body = ['reason' => 'خطأ'], array $headers = []) => $this->tenantJson('POST', '/api/dashboard/credits/'.($id ?? $this->credit->id).'/void', $body, headers: $headers);
});

test('BR-C07 the merchant lists a customer\'s credits with what is left', function (): void {
    $response = $this->tenantJson('GET', "/api/dashboard/customers/{$this->customer->id}/credits")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->credit->id)
        ->assertJsonPath('data.0.amount', '180.00')
        ->assertJsonPath('data.0.remaining', '180.00');

    expectDocumentedShape($response->json('data.0'), 'CustomerCredit');
});

test('BR-C07 the merchant applies credit to another order and gets the credit collection with the order balance', function (): void {
    $response = ($this->apply)(['credit_id' => $this->credit->id, 'amount' => '100'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'payment')
        ->assertJsonPath('data.method', 'credit')
        ->assertJsonPath('data.customer_credit_id', $this->credit->id)
        ->assertJsonPath('data.amount', '100.00')
        ->assertJsonPath('data.order.collected_amount', '100.00');

    expectDocumentedShape($response->json('data'), 'Collection');
    $this->assertDatabaseHas('customer_credits', ['id' => $this->credit->id, 'remaining' => '80.00']);
    expect(AuditLog::withoutTenantScope()->where('action', 'customer_credit.applied')->sole()->actor_id)->toBe($this->owner->id);
});

test('BR-C07 more than the credit holds is 422 exceeds_credit', function (): void {
    ($this->apply)(['credit_id' => $this->credit->id, 'amount' => '180.01'])->assertUnprocessable()->assertJsonPath('error.code', 'exceeds_credit')->assertJsonPath('error.details.remaining', '180.00');
});

test('applying credit is validated', function (array $body, string $field): void {
    ($this->apply)($body)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing credit' => [['amount' => '10'], 'credit_id'],
    'credit not an id' => [['credit_id' => 'x', 'amount' => '10'], 'credit_id'],
    'missing amount' => [['credit_id' => 1], 'amount'],
    'zero amount' => [['credit_id' => 1, 'amount' => '0'], 'amount'],
    'three decimals' => [['credit_id' => 1, 'amount' => '1.001'], 'amount'],
]);

test('D55 the same Idempotency-Key twice applies the credit once', function (): void {
    ($this->apply)(['credit_id' => $this->credit->id, 'amount' => '10'], headers: ['Idempotency-Key' => 'credit-1'])->assertCreated();
    ($this->apply)(['credit_id' => $this->credit->id, 'amount' => '10'], headers: ['Idempotency-Key' => 'credit-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    $this->assertDatabaseHas('customer_credits', ['id' => $this->credit->id, 'remaining' => '170.00']);
});

test('BR-C07 the merchant voids an unused credit, and a second void is 409', function (): void {
    $response = ($this->void)()->assertOk()->assertJsonPath('data.is_voided', true)->assertJsonPath('data.void_reason', 'خطأ');

    expectDocumentedShape($response->json('data'), 'CustomerCredit');
    ($this->void)()->assertConflict()->assertJsonPath('error.code', 'credit_voided');
});

test('BR-C07 a credit partly applied is 409 credit_applied', function (): void {
    ($this->apply)(['credit_id' => $this->credit->id, 'amount' => '30'])->assertCreated();

    ($this->void)()->assertConflict()->assertJsonPath('error.code', 'credit_applied')->assertJsonPath('error.details.applied', '30.00');
});

test('the void reason is required', function (): void {
    ($this->void)(body: [])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['reason']]]);
});

test('BR-T04 another tenant\'s customer, order and credit are 404 here and stay as they were', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): CustomerCredit => CustomerCredit::factory()->create(), $other);
    $theirOrder = $this->inTenant(fn (): Order => Order::factory()->delivered()->create(['items_total' => '100.00', 'order_total' => '100.00']), $other);

    $this->tenantJson('GET', "/api/dashboard/customers/{$theirs->customer_id}/credits")->assertNotFound();
    ($this->apply)(['credit_id' => $theirs->id, 'amount' => '10'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    ($this->apply)(['credit_id' => $this->credit->id, 'amount' => '10'], $theirOrder->number + 1000)->assertNotFound();
    ($this->void)($theirs->id)->assertNotFound();

    $this->assertDatabaseHas('customer_credits', ['id' => $theirs->id, 'voided_at' => null, 'remaining' => '100.00']);
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): CustomerCredit => CustomerCredit::factory()->create(), $other);

    $this->tenantJson('POST', "/api/dashboard/credits/{$theirs->id}/void", ['reason' => 'خطأ'], tenant: $other)->assertUnauthorized();
});
