<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;

/*
| A delivered order of 6 cartons (20 pieces) at 90.00 = 540.00; the SKU has 280 pieces left after the delivery.
*/

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل']);
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    $this->inTenant(fn (): Order => app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered), $this->owner->id));
    $this->line = $this->inTenant(fn (): OrderItem => OrderItem::query()->where('order_id', $this->order->id)->sole());

    $this->body = fn (int $quantity = 2, array $extra = []): array => ['reason' => 'مرتجع نهاية الموسم', 'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]], ...$extra];
    $this->post = fn (array $body, ?int $number = null, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/orders/'.($number ?? $this->order->number).'/returns', $body, headers: $headers);
    $this->recorded = fn (int $quantity = 2): OrderReturn => $this->inTenant(fn (): OrderReturn => app(RecordReturn::class)->handle($this->order, new ReturnData('مرتجع', [new ReturnLineData($this->line->id, $quantity)]), $this->owner->id));
    $this->void = fn (int $id, array $body = ['reason' => 'خطأ'], array $headers = []) => $this->tenantJson('POST', "/api/dashboard/returns/{$id}/void", $body, headers: $headers);
});

test('BR-R01 BR-R03 the merchant records a return and gets it back with the order\'s new balance', function (): void {
    $this->freezeSecond();

    $response = ($this->post)(($this->body)())
        ->assertCreated()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.total', '180.00')
        ->assertJsonPath('data.refund_amount', '0.00')
        ->assertJsonPath('data.reason', 'مرتجع نهاية الموسم')
        ->assertJsonPath('data.returned_at', now()->toIso8601String())
        ->assertJsonPath('data.recorded_by', $this->owner->id)
        ->assertJsonPath('data.is_voided', false)
        ->assertJsonPath('data.order', [
            'id' => $this->order->id, 'number' => $this->order->number, 'order_total' => '540.00', 'returned_total' => '180.00',
            'net_total' => '360.00', 'collected_amount' => '0.00', 'collection_status' => 'unpaid',
        ])
        ->assertJsonPath('data.items.0.order_item_id', $this->line->id)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.quantity_base', 40)
        ->assertJsonPath('data.items.0.line_total', '180.00');

    expectDocumentedShape($response->json('data'), 'OrderReturn');
    expectDocumentedShape($response->json('data.items.0'), 'OrderReturnLine');
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 320]);
    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'returned_total' => '180.00']);
});

test('BR-R01 a return on an order that is not delivered is 409 return_not_allowed', function (): void {
    $pending = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5]);
    $line = $this->inTenant(fn (): OrderItem => OrderItem::query()->where('order_id', $pending->id)->sole());

    ($this->post)(['reason' => 'خطأ', 'items' => [['order_item_id' => $line->id, 'quantity' => 1]]], $pending->number)
        ->assertConflict()
        ->assertJsonPath('error.code', 'return_not_allowed')
        ->assertJsonPath('error.details.status', 'pending');
});

test('BR-R02 more than returnable is 422 with the offending line in items', function (): void {
    ($this->post)(($this->body)(7))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'return_exceeds_delivered')
        ->assertJsonPath('error.items', [['order_item_id' => $this->line->id, 'requested' => 7, 'returnable' => 6, 'reason' => 'return_exceeds_delivered']]);
});

test('BR-R04 an over-collected order answers 422 refund_required with the amount, then records with refund confirmed', function (): void {
    $this->inTenant(fn (): CashCollection => app(RecordCollection::class)->handle($this->order, new CollectionData('540.00'), $this->owner->id));

    ($this->post)(($this->body)())->assertUnprocessable()->assertJsonPath('error.code', 'refund_required')->assertJsonPath('error.details.refund_amount', '180.00');
    $this->assertDatabaseCount('order_returns', 0);

    ($this->post)(($this->body)(2, ['refund' => true]))
        ->assertCreated()
        ->assertJsonPath('data.refund_amount', '180.00')
        ->assertJsonPath('data.order.collected_amount', '360.00')
        ->assertJsonPath('data.order.collection_status', 'collected');

    $this->assertDatabaseHas('collections', ['order_id' => $this->order->id, 'kind' => 'refund', 'amount' => '180.00']);
});

test('BR-L recording a return is audited with the merchant as actor', function (): void {
    ($this->post)(($this->body)())->assertCreated();

    expect(AuditLog::withoutTenantScope()->where('action', 'order.returned')->sole()->only(['actor_type', 'actor_id']))
        ->toBe(['actor_type' => App\Modules\Audit\Enums\ActorType::TenantUser, 'actor_id' => $this->owner->id]);
});

test('every return field is validated', function (array $body, string $field): void {
    ($this->post)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing reason' => [['items' => [['order_item_id' => 1, 'quantity' => 1]]], 'reason'],
    'reason too long' => [['reason' => str_repeat('س', 301), 'items' => [['order_item_id' => 1, 'quantity' => 1]]], 'reason'],
    'missing items' => [['reason' => 'x'], 'items'],
    'empty items' => [['reason' => 'x', 'items' => []], 'items'],
    'too many items' => [['reason' => 'x', 'items' => array_fill(0, 201, ['order_item_id' => 1, 'quantity' => 1])], 'items'],
    'unknown item key' => [['reason' => 'x', 'items' => [['order_item_id' => 1, 'quantity' => 1, 'price' => '1.00']]], 'items.0'],
    'missing item id' => [['reason' => 'x', 'items' => [['quantity' => 1]]], 'items.0.order_item_id'],
    'non-integer item id' => [['reason' => 'x', 'items' => [['order_item_id' => 'a', 'quantity' => 1]]], 'items.0.order_item_id'],
    'duplicate item' => [['reason' => 'x', 'items' => [['order_item_id' => 1, 'quantity' => 1], ['order_item_id' => 1, 'quantity' => 1]]], 'items.0.order_item_id'],
    'missing quantity' => [['reason' => 'x', 'items' => [['order_item_id' => 1]]], 'items.0.quantity'],
    'zero quantity' => [['reason' => 'x', 'items' => [['order_item_id' => 1, 'quantity' => 0]]], 'items.0.quantity'],
    'future date' => [['reason' => 'x', 'returned_at' => '2999-01-01T00:00:00Z', 'items' => [['order_item_id' => 1, 'quantity' => 1]]], 'returned_at'],
    'not a date' => [['reason' => 'x', 'returned_at' => 'yesterday-ish', 'items' => [['order_item_id' => 1, 'quantity' => 1]]], 'returned_at'],
    'refund not boolean' => [['reason' => 'x', 'refund' => 'maybe', 'items' => [['order_item_id' => 1, 'quantity' => 1]]], 'refund'],
]);

test('D55 the same Idempotency-Key twice records one return and replays the answer', function (): void {
    ($this->post)(($this->body)(), headers: ['Idempotency-Key' => 'return-1'])->assertCreated();

    ($this->post)(($this->body)(), headers: ['Idempotency-Key' => 'return-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    $this->assertDatabaseCount('order_returns', 1);
});

test('BR-R02 the order\'s returns list them oldest first with what each line can still take back', function (): void {
    $first = ($this->recorded)(2);
    $voided = ($this->recorded)(1);
    ($this->void)($voided->id)->assertOk();

    $response = $this->tenantJson('GET', '/api/dashboard/orders/'.$this->order->number.'/returns')
        ->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.is_voided', true)
        ->assertJsonPath('returnable', [[
            'order_item_id' => $this->line->id, 'product_name' => $this->line->product_name, 'sku_code' => $this->line->sku_code,
            'unit' => 'كرتونة', 'unit_price' => '90.00', 'ordered' => 6, 'returned' => 2, 'returnable' => 4,
        ]])
        ->assertJsonMissingPath('data.0.order');

    expectDocumentedShape($response->json('data.0'), 'OrderReturn');
    expectDocumentedShape($response->json('returnable.0'), 'ReturnableLine');
});

test('BR-R05 the returns list shows every return of the store with its order and customer, filtered', function (): void {
    $return = ($this->recorded)();
    $this->inTenant(fn (): OrderReturn => OrderReturn::factory()->create(), $this->otherTenant());

    $response = $this->tenantJson('GET', '/api/dashboard/returns?q=الأمل')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $return->id)
        ->assertJsonPath('data.0.customer', ['id' => $this->customer->id, 'name' => 'مكتبة الأمل'])
        ->assertJsonPath('data.0.order.number', $this->order->number);

    expectDocumentedShape($response->json('data.0'), 'OrderReturn');
    $this->tenantJson('GET', '/api/dashboard/returns?customer_id='.($this->customer->id + 1000))->assertOk()->assertJsonPath('meta.total', 0);
});

test('the returns list filters are validated', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/returns?'.$query)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'bad from' => ['from=10-10-2026', 'from'],
    'to before from' => ['from=2026-10-10&to=2026-10-09', 'to'],
    'bad customer' => ['customer_id=0', 'customer_id'],
    'search too long' => ['q='.str_repeat('a', 101), 'q'],
]);

test('BR-R06 the merchant voids a return and the stock and totals follow', function (): void {
    $return = ($this->recorded)();
    $this->freezeSecond();

    ($this->void)($return->id, ['reason' => 'سجلناه بالخطأ'])
        ->assertOk()
        ->assertJsonPath('data.is_voided', true)
        ->assertJsonPath('data.void_reason', 'سجلناه بالخطأ')
        ->assertJsonPath('data.voided_by', $this->owner->id)
        ->assertJsonPath('data.voided_at', now()->toIso8601String())
        ->assertJsonPath('data.order.returned_total', '0.00')
        ->assertJsonPath('data.items.0.quantity', 2);

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 280]);
});

test('BR-R06 a second void is 409 already_voided and a return with a live refund is 409 return_has_refund', function (): void {
    $return = ($this->recorded)();
    ($this->void)($return->id)->assertOk();
    ($this->void)($return->id)->assertConflict()->assertJsonPath('error.code', 'already_voided');

    $this->inTenant(fn (): CashCollection => app(RecordCollection::class)->handle($this->order, new CollectionData('540.00'), $this->owner->id));
    $refunded = $this->inTenant(fn (): OrderReturn => app(RecordReturn::class)->handle($this->order, new ReturnData('مرتجع', [new ReturnLineData($this->line->id, 1)], refund: true), $this->owner->id));

    ($this->void)($refunded->id)->assertConflict()->assertJsonPath('error.code', 'return_has_refund')->assertJsonPath('error.details.refund_amount', '90.00');
});

test('the void reason is required and bounded', function (array $body): void {
    ($this->void)(($this->recorded)()->id, $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['reason']]]);
})->with([
    'missing' => [[]],
    'too long' => [['reason' => str_repeat('س', 301)]],
]);

test('D55 the same Idempotency-Key twice answers the first void again', function (): void {
    $return = ($this->recorded)();

    ($this->void)($return->id, ['reason' => 'خطأ'], ['Idempotency-Key' => 'void-return-1'])->assertOk();
    ($this->void)($return->id, ['reason' => 'خطأ'], ['Idempotency-Key' => 'void-return-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
});

test('BR-T04 another tenant\'s order and return are 404 and stay as they were', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): OrderReturn => OrderReturn::factory()->create(), $other);
    $theirOrder = $this->inTenant(fn (): Order => Order::query()->findOrFail($theirs->order_id), $other);

    $this->tenantJson('GET', '/api/dashboard/orders/'.$theirOrder->number.'/returns')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    ($this->post)(($this->body)(), $theirOrder->number)->assertNotFound();
    ($this->void)($theirs->id)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('order_returns', ['id' => $theirs->id, 'voided_at' => null]);
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host, even for a return that exists there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): OrderReturn => OrderReturn::factory()->create(), $other);

    $this->tenantJson('POST', "/api/dashboard/returns/{$theirs->id}/void", ['reason' => 'خطأ'], tenant: $other)->assertUnauthorized();

    $this->assertDatabaseHas('order_returns', ['id' => $theirs->id, 'voided_at' => null]);
});

test('BR-R06 the dashboard order detail carries the returns, the returned total and the net total', function (): void {
    ($this->recorded)();

    $response = $this->tenantJson('GET', '/api/dashboard/orders/'.$this->order->number)
        ->assertOk()
        ->assertJsonPath('data.returned_total', '180.00')
        ->assertJsonPath('data.net_total', '360.00')
        ->assertJsonPath('data.returns.0.number', 1)
        ->assertJsonPath('data.history.2.note', 'مرتجع #1');

    expectDocumentedShape($response->json('data'), 'DashboardOrderDetail');
});

test('BR-C07 an over-collected return can be kept as customer credit instead of cash', function (): void {
    $this->inTenant(fn (): CashCollection => app(RecordCollection::class)->handle($this->order, new CollectionData('540.00'), $this->owner->id));

    ($this->post)(($this->body)(2, ['refund' => true, 'refund_to' => 'credit']))
        ->assertCreated()
        ->assertJsonPath('data.refund_amount', '180.00')
        ->assertJsonPath('data.refund_method', 'credit');

    $this->assertDatabaseHas('customer_credits', ['customer_id' => $this->customer->id, 'amount' => '180.00', 'remaining' => '180.00']);
    $this->assertDatabaseHas('collections', ['order_id' => $this->order->id, 'kind' => 'refund', 'method' => 'credit']);
});

test('the refund destination is validated', function (): void {
    ($this->post)(($this->body)(1, ['refund_to' => 'bank']))->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['refund_to']]]);
});
