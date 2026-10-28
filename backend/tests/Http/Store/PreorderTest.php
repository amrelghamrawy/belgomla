<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

/*
| BR-O14, D88 on the storefront (08-api-contract.md §3): pre-order lines in the cart (`kind: preorder`), the
| confirmation `POST /api/store/checkout/preorder`, the customer's pre-orders and their cancellation. The SKU is a Coming
| Soon book with pre-orders on, sold in cartons of 20, MOQ 5, 90.00 and 85.00 from 10 cartons, no stock.
*/

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer($this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']));
    $this->sku = $this->preorderableSku(['code' => 'SLH-27'], limit: 30);
    $this->address = $this->addressOf($this->customer);
    $this->add = fn (int $quantity, string $kind = 'preorder', ?int $skuId = null) => $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $skuId ?? $this->sku->id, 'quantity' => $quantity, 'kind' => $kind]);
    $this->confirm = fn (array $body = [], array $headers = []) => $this->tenantJson('POST', '/api/store/checkout/preorder', ['address_id' => $this->address->id, ...$body], headers: $headers);
});

test('BR-O14 the product page offers the pre-order with the expected day and what remains under the cap', function (): void {
    $product = $this->inTenant(fn (): Product => Product::query()->findOrFail($this->sku->product_id));

    $this->tenantJson('GET', "/api/store/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.status', 'coming_soon')
        ->assertJsonPath('data.sku.in_stock', false)
        ->assertJsonPath('data.preorder', ['enabled' => true, 'expected_at' => '2026-12-01', 'remaining' => 30]);
});

test('BR-O14 a pre-order line goes into the cart apart from the order lines, without stock', function (): void {
    $response = ($this->add)(12)->assertOk();

    $response->assertJsonPath('data.items', [])
        ->assertJsonPath('data.preorders.items_total', '1020.00')
        ->assertJsonPath('data.preorders.items.0.sku_id', $this->sku->id)
        ->assertJsonPath('data.preorders.items.0.unit_price', '85.00')
        ->assertJsonPath('data.preorders.items.0.expected_at', '2026-12-01');
    expectDocumentedShape($response->json('data'), 'Cart');
    expectDocumentedShape($response->json('data.preorders.items.0'), 'PreorderCartItem');
    $this->assertDatabaseHas('cart_items', ['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 12, 'kind' => 'preorder']);
});

test('BR-P04 the same coming soon product cannot go in as an order line', function (): void {
    ($this->add)(12, 'order')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items', [['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'product_inactive']]);
});

test('BR-O14 an active product cannot be pre-ordered', function (): void {
    ($this->add)(5, skuId: $this->orderableSku()->id)
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0.reason', 'preorder_unavailable');
});

test('BR-O14 the cart kind is order or preorder', function (): void {
    ($this->add)(5, 'backorder')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrorFor('kind', 'error.details');
});

test('BR-O14 the pre-order line is changed and removed by its kind', function (): void {
    ($this->add)(5)->assertOk();

    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}", ['quantity' => 8, 'kind' => 'preorder'])->assertOk()->assertJsonPath('data.preorders.items.0.quantity', 8);
    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}", ['quantity' => 8])->assertNotFound();
    $this->tenantJson('DELETE', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}?kind=preorder")->assertOk()->assertJsonPath('data.preorders.items', []);

    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-T04 another store\'s sku cannot be pre-ordered here', function (): void {
    $theirs = $this->preorderableSku(tenant: $this->otherTenant());

    ($this->add)(5, skuId: $theirs->id)->assertNotFound();
});

test('BR-O14 confirming answers 201 with the pre-order detail; the order lines stay in the cart', function (): void {
    $active = $this->orderableSku();
    ($this->add)(5, 'order', $active->id)->assertOk();
    ($this->add)(12)->assertOk();

    $response = ($this->confirm)(['customer_note' => 'قبل المدارس'])
        ->assertCreated()
        ->assertJsonPath('data.order.kind', 'preorder')
        ->assertJsonPath('data.order.status', 'pending')
        ->assertJsonPath('data.order.preorder_state', 'open')
        ->assertJsonPath('data.order.order_total', '1020.00')
        ->assertJsonPath('data.order.customer_note', 'قبل المدارس')
        ->assertJsonPath('data.order.items.0.converted_quantity', 0)
        ->assertJsonPath('data.order.conversions', []);

    expectDocumentedShape($response->json('data.order'), 'OrderDetail');
    $this->assertDatabaseHas('cart_items', ['sku_id' => $active->id, 'kind' => 'order']);
    $this->assertDatabaseMissing('cart_items', ['kind' => 'preorder']);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'reserved' => 0]);
});

test('BR-O14 past the cap the confirmation answers 422 checkout_failed with what remains', function (): void {
    $this->placePreorder($this->makeCustomer(), [$this->sku->id => 25]);
    ($this->add)(10)->assertOk();

    ($this->confirm)()
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'checkout_failed')
        ->assertJsonPath('error.items', [['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'preorder_limit', 'requested' => 10, 'remaining' => 5]]);

    $this->assertDatabaseHas('cart_items', ['sku_id' => $this->sku->id, 'kind' => 'preorder']);
});

test('BR-O14 an empty pre-order cart answers 422 cart_empty', function (): void {
    ($this->confirm)()->assertUnprocessable()->assertJsonPath('error.code', 'cart_empty');
});

test('BR-O14 the address must be one of the customer\'s: 422 on address_id', function (): void {
    ($this->add)(5)->assertOk();

    ($this->confirm)(['address_id' => $this->addressOf($this->makeCustomer())->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrorFor('address_id', 'error.details');
});

test('BR-O14 the confirmation validates its body', function (array $body, string $field): void {
    ($this->confirm)($body)->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'address missing' => [['address_id' => null], 'address_id'],
    'note too long' => [['customer_note' => str_repeat('ن', 1001)], 'customer_note'],
]);

test('D55 the same Idempotency-Key twice confirms once and replays the answer', function (): void {
    ($this->add)(5)->assertOk();

    ($this->confirm)(headers: ['Idempotency-Key' => 'pre-1'])->assertCreated();
    ($this->confirm)(headers: ['Idempotency-Key' => 'pre-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Order::withoutTenantScope()->count())->toBe(1);
});

test('BR-CU05 a blocked customer cannot pre-order', function (): void {
    $this->inTenant(fn () => $this->customer->forceFill(['status' => CustomerStatus::Blocked])->save());

    ($this->confirm)()->assertForbidden();
});

test('Tech §6 a customer session of this store is refused on another store\'s host', function (): void {
    $this->tenantJson('POST', '/api/store/checkout/preorder', ['address_id' => $this->address->id], $this->otherTenant())->assertUnauthorized();
});

test('BR-L the confirmation is audited with the customer as actor and the kind', function (): void {
    ($this->add)(5)->assertOk();

    ($this->confirm)()->assertCreated();

    expect(AuditLog::withoutTenantScope()->where('action', 'order.created')->sole()->new_values)->toMatchArray(['kind' => 'preorder']);
});

test('BR-O14 the customer lists their pre-orders apart and cancels one', function (): void {
    $order = $this->placeOrder($this->customer, [$this->orderableSku()->id => 5]);
    $preorder = $this->placePreorder($this->customer, [$this->sku->id => 5]);

    $this->tenantJson('GET', '/api/store/orders?kind=preorder')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $preorder->number);
    $this->tenantJson('GET', '/api/store/orders?kind=order')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $order->number);
    $this->tenantJson('POST', "/api/store/orders/{$preorder->number}/cancel", ['reason' => 'غيرت رأيي'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.preorder_state', 'cancelled');
});

test('D88 a pre-order converted in full cannot be cancelled: 409', function (): void {
    $preorder = $this->placePreorder($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => $preorder->forceFill(['converted_at' => now()])->save());

    $this->tenantJson('POST', "/api/store/orders/{$preorder->number}/cancel", ['reason' => 'متأخر'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition');

    $this->assertDatabaseHas('orders', ['id' => $preorder->id, 'status' => OrderStatus::Pending->value]);
});

test('D88 the customer sees the orders a pre-order became, and the pre-order an order came from', function (): void {
    $preorder = $this->placePreorder($this->customer, [$this->sku->id => 5]);
    $order = $this->inTenant(fn (): Order => Order::factory()->for($this->customer)->create(['converted_from_id' => $preorder->id]));

    $this->tenantJson('GET', "/api/store/orders/{$preorder->number}")->assertOk()->assertJsonPath('data.conversions.0.number', $order->number);
    $this->tenantJson('GET', "/api/store/orders/{$order->number}")->assertOk()->assertJsonPath('data.converted_from', $preorder->number);
});
