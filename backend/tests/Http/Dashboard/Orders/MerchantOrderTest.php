<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;

/*
| BR-O11, D84: POST /api/dashboard/orders and POST /api/dashboard/orders/quote (08-api-contract.md §9).
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->address = $this->addressOf($this->customer);
    $this->sku = $this->orderableSku(['code' => 'ADW-SCI']);
    $this->body = fn (array $overrides = []): array => [
        'customer_id' => $this->customer->id,
        'address_id' => $this->address->id,
        'lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 12]],
        ...$overrides,
    ];
    $this->place = fn (array $body = [], array $headers = []) => $this->tenantJson('POST', '/api/dashboard/orders', ($this->body)($body), headers: $headers);
    $this->quote = fn (array $body = []) => $this->tenantJson('POST', '/api/dashboard/orders/quote', [
        'customer_id' => $this->customer->id,
        'lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 12]],
        ...$body,
    ]);
    $this->mov = fn (string $amount) => TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => $amount]);
});

test('BR-O11 the merchant places an order for a customer and gets the order page, placed by them', function (): void {
    $response = ($this->place)(['note' => 'طلب بالتليفون'])
        ->assertCreated()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.order_total', '1020.00')
        ->assertJsonPath('data.customer_note', 'طلب بالتليفون')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.placed_by', 'tenant_user')
        ->assertJsonPath('data.placed_by_id', $this->owner->id)
        ->assertJsonPath('data.history.0.by', 'tenant_user')
        ->assertJsonPath('data.history.0.actor_id', $this->owner->id)
        ->assertJsonPath('data.items.0.sku_code', 'ADW-SCI')
        ->assertJsonPath('data.messages', []);

    expectDocumentedShape($response->json('data'), 'DashboardOrderDetail');
});

test('BR-O11 BR-L the order is audited as order.created by the dashboard user with placed_by', function (): void {
    ($this->place)()->assertCreated();

    expect(AuditLog::withoutTenantScope()->where('action', 'order.created')->sole()->only(['actor_type', 'actor_id', 'new_values']))->toEqual([
        'actor_type' => App\Modules\Audit\Enums\ActorType::TenantUser,
        'actor_id' => $this->owner->id,
        'new_values' => ['number' => 1, 'status' => 'pending', 'order_total' => '1020.00', 'lines' => 1, 'placed_by' => ['type' => 'tenant_user', 'id' => $this->owner->id]],
    ]);
});

test('BR-O11 with notify_customer the placed message shows on the order', function (): void {
    ($this->place)(['notify_customer' => true])
        ->assertCreated()
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.purpose', 'order_status');
});

test('BR-O11 the customer sees the order among their own orders', function (): void {
    $number = ($this->place)()->assertCreated()->json('data.number');

    $this->actingAsCustomer($this->customer);

    $this->tenantJson('GET', '/api/store/orders')->assertOk()->assertJsonPath('data.0.number', $number);
});

test('merchant order validation', function (array $body, string $field): void {
    ($this->place)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'customer missing' => [['customer_id' => null], 'customer_id'],
    'customer not an id' => [['customer_id' => 'x'], 'customer_id'],
    'address missing' => [['address_id' => null], 'address_id'],
    'no lines' => [['lines' => []], 'lines'],
    'lines not a list' => [['lines' => 'x'], 'lines'],
    'too many lines' => [['lines' => array_map(fn (int $id): array => ['sku_unit_id' => $id, 'quantity' => 1], range(1, 201))], 'lines'],
    'line with an unknown key' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 1, 'unit_price' => '1.00']]], 'lines.0'],
    'sku missing' => [['lines' => [['quantity' => 1]]], 'lines.0.sku_unit_id'],
    'sku twice' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 1], ['sku_unit_id' => 1, 'quantity' => 2]]], 'lines.0.sku_unit_id'],
    'quantity missing' => [['lines' => [['sku_unit_id' => 1]]], 'lines.0.quantity'],
    'quantity zero' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 0]]], 'lines.0.quantity'],
    'quantity not whole' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 1.5]]], 'lines.0.quantity'],
    'quantity too large' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 1000001]]], 'lines.0.quantity'],
    'note too long' => [['note' => str_repeat('ن', 1001)], 'note'],
    'notify not boolean' => [['notify_customer' => 'yes'], 'notify_customer'],
    'override not boolean' => [['override_mov' => 'maybe'], 'override_mov'],
    'line discount with an unknown key' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 5, 'discount' => ['type' => 'amount', 'value' => '1', 'reason' => 'x']]]], 'lines.0.discount'],
    'line discount type unknown' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 5, 'discount' => ['type' => 'gift', 'value' => '1']]]], 'lines.0.discount.type'],
    'line discount without a value' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 5, 'discount' => ['type' => 'amount']]]], 'lines.0.discount.value'],
    'line discount of 100%' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 5, 'discount' => ['type' => 'percent', 'value' => '100']]]], 'lines.0.discount.value'],
    'order discount without a reason' => [['discount' => ['type' => 'amount', 'value' => '5']], 'discount.reason'],
    'order discount of 100%' => [['discount' => ['type' => 'percent', 'value' => '100', 'reason' => 'هدية']], 'discount.value'],
]);

test('D85 discounts given on the form apply to the new order, after the checks at list prices', function (): void {
    ($this->mov)('1000.00');

    $response = ($this->place)([
        'lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 12, 'discount' => ['type' => 'percent', 'value' => '10']]],
        'discount' => ['type' => 'amount', 'value' => '18', 'reason' => 'طلب بالتليفون'],
    ])->assertCreated();

    $response->assertJsonPath('data.items.0.list_unit_price', '85.00')
        ->assertJsonPath('data.items.0.unit_price', '76.50')
        ->assertJsonPath('data.items_total', '918.00')
        ->assertJsonPath('data.discount', ['type' => 'amount', 'value' => '18.00', 'amount' => '18.00', 'reason' => 'طلب بالتليفون'])
        ->assertJsonPath('data.order_total', '900.00');
    expect(AuditLog::withoutTenantScope()->where('action', 'order.discount_changed')->count())->toBe(1);
});

test('D85 a sales user without a cap cannot give a discount on the form, and no order is placed', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Sales]));

    ($this->place)(['lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 12, 'discount' => ['type' => 'amount', 'value' => '1']]]])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'discount_over_limit');

    expect($this->inTenant(fn (): int => Order::query()->count()))->toBe(0)
        ->and($this->inTenant(fn (): int => SkuInventory::query()->findOrFail($this->sku->id)->reserved))->toBe(0);
});

test('BR-O11 an unknown sku is a field error on its line', function (): void {
    ($this->place)(['lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 5], ['sku_unit_id' => 999999, 'quantity' => 5]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.details', ['lines.1.sku_unit_id' => ['المنتج غير موجود']]);
});

test('BR-O11 BR-PR06 a failing line answers checkout_failed with the sku', function (): void {
    ($this->place)(['lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 3]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'checkout_failed')
        ->assertJsonPath('error.items', [['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'below_moq', 'moq' => 5]]);
});

test('BR-O11 a blocked customer answers 409 customer_blocked', function (): void {
    $this->inTenant(fn () => $this->customer->forceFill(['status' => CustomerStatus::Blocked])->save());

    ($this->place)()
        ->assertConflict()
        ->assertJsonPath('error.code', 'customer_blocked')
        ->assertJsonPath('error.message', 'هذا العميل محظور، ألغِ الحظر أولًا لتسجيل طلب له')
        ->assertJsonPath('error.details.status', 'blocked');
});

test('D84 the owner may waive the minimum order value for one order', function (): void {
    ($this->mov)('1500.00');

    ($this->place)()->assertUnprocessable()->assertJsonPath('error.details.mov', ['required' => '1500.00', 'current' => '1020.00']);
    ($this->place)(['override_mov' => true])->assertCreated();
});

test('D84 who may waive the minimum order value', function (TenantUserRole $role, int $status): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));
    ($this->mov)('1500.00');

    ($this->place)(['override_mov' => true])->assertStatus($status);
})->with([
    'manager' => [TenantUserRole::Manager, 201],
    'sales' => [TenantUserRole::Sales, 403],
]);

test('D84 sales may place an order without the override', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Sales]));

    ($this->place)(['override_mov' => false])->assertCreated();
});

test('D55 the same Idempotency-Key twice places the order once', function (): void {
    ($this->place)(headers: ['Idempotency-Key' => 'phone-order-1'])->assertCreated();

    ($this->place)(headers: ['Idempotency-Key' => 'phone-order-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($this->inTenant(fn (): int => Order::query()->count()))->toBe(1)
        ->and($this->inTenant(fn (): int => SkuInventory::query()->findOrFail($this->sku->id)->reserved))->toBe(240);
});

test('BR-T04 a customer of another tenant is a field error and nothing is written there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->makeCustomer(tenant: $other);
    $theirAddress = $this->addressOf($theirs);

    ($this->place)(['customer_id' => $theirs->id, 'address_id' => $theirAddress->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.details', ['customer_id' => ['العميل غير موجود']]);

    $this->assertDatabaseCount('orders', 0);
});

test('a dashboard session of another tenant cannot place orders here', function (): void {
    $other = $this->otherTenant();

    $this->tenantJson('POST', '/api/dashboard/orders', ($this->body)(), $other)->assertUnauthorized();
});

test('BR-O11 the quote prices every line with its tiers, stock and problem, and saves nothing', function (): void {
    $short = $this->orderableSku(['code' => 'SHORT'], onHand: 60, name: 'كراسة');

    $response = ($this->quote)(['lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 12], ['sku_unit_id' => $this->defaultUnitId($short), 'quantity' => 5]]])
        ->assertOk()
        ->assertJsonPath('data.lines.0', [
            'sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'product_name' => 'الأضواء علوم', 'sku_code' => 'ADW-SCI', 'sku_name' => 'الأضواء علوم', 'unit' => 'كرتونة',
            'conversion_factor' => 20, 'quantity' => 12, 'moq' => 5, 'available' => 20, 'is_bundle' => false, 'cover_price' => null, 'unit_price' => '85.00',
            'discount_percent' => null, 'tier_min_quantity' => 10, 'price_source' => 'tier', 'line_total' => '1020.00',
            'tiers' => [['min_quantity' => 10, 'unit_price' => '85.00']], 'problem' => null,
        ])
        ->assertJsonPath('data.lines.1.problem', ['reason' => 'insufficient_stock', 'requested' => 5, 'available' => 3])
        ->assertJsonPath('data.items_total', '1470.00')
        ->assertJsonPath('data.mov', ['enabled' => false, 'amount' => null, 'satisfied' => true])
        ->assertJsonPath('data.credit', null);

    expectDocumentedShape($response->json('data'), 'OrderQuote');
    expectDocumentedShape($response->json('data.lines.0'), 'OrderQuoteLine');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    expect($this->inTenant(fn (): int => SkuInventory::query()->findOrFail($this->sku->id)->reserved))->toBe(0);
});

test('BR-MOV02 BR-C05 the quote shows the minimum order value and the credit limit', function (): void {
    ($this->mov)('1500.00');
    $this->inTenant(fn () => $this->customer->forceFill(['credit_limit' => '1000.00'])->save());

    ($this->quote)()
        ->assertOk()
        ->assertJsonPath('data.mov', ['enabled' => true, 'amount' => '1500.00', 'satisfied' => false])
        ->assertJsonPath('data.credit', ['limit' => '1000.00', 'exposure' => '0.00', 'order_total' => '1020.00']);
});

test('quote validation', function (array $body, string $field): void {
    ($this->quote)($body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'customer missing' => [['customer_id' => null], 'customer_id'],
    'no lines' => [['lines' => []], 'lines'],
    'quantity zero' => [['lines' => [['sku_unit_id' => 1, 'quantity' => 0]]], 'lines.0.quantity'],
    'unknown customer' => [['customer_id' => 999999], 'customer_id'],
    'unknown sku' => [['lines' => [['sku_unit_id' => 999999, 'quantity' => 1]]], 'lines.0.sku_unit_id'],
]);

test('BR-T04 the quote does not see another tenant\'s sku', function (): void {
    $theirs = $this->orderableSku(tenant: $this->otherTenant());

    ($this->quote)(['lines' => [['sku_unit_id' => $this->defaultUnitId($theirs), 'quantity' => 5]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.details', ['lines.0.sku_unit_id' => ['المنتج غير موجود']]);
});

test('BR-O11 merchant orders show as placed by the merchant in the list', function (): void {
    ($this->place)()->assertCreated();
    $this->travel(1)->minutes();
    $this->placeOrder($this->customer, [$this->sku->id => 5]);

    $this->tenantJson('GET', '/api/dashboard/orders')
        ->assertOk()
        ->assertJsonPath('data.0.placed_by', 'customer')
        ->assertJsonPath('data.0.placed_by_id', $this->customer->id)
        ->assertJsonPath('data.1.placed_by', 'tenant_user');
});

test('BR-O11 no message is sent when notify_customer is left out', function (): void {
    ($this->place)()->assertCreated();

    expect($this->inTenant(fn (): int => OutboundMessage::query()->count()))->toBe(0);
});

/*
| D91 (V2 Task 13): the orderable SKU is also sold by the piece (factor 1, 4.50, MOQ 10, 50+ → 4.00).
*/

test('D91 BR-O11 an order may carry one SKU in two units, each snapshotted in its unit, the stock reserved once per line', function (): void {
    $piece = $this->addUnit($this->sku, ['unit_id' => $this->inTenant(fn (): int => App\Modules\Catalog\Models\Unit::factory()->create(['name' => 'قطعة'])->id), 'conversion_factor' => 1, 'base_price' => '4.50', 'moq' => 10]);

    ($this->place)(['lines' => [['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 5], ['sku_unit_id' => $piece->id, 'quantity' => 15]]])
        ->assertCreated()
        ->assertJsonPath('data.items.0.unit', 'كرتونة')
        ->assertJsonPath('data.items.0.sku_unit_id', $this->defaultUnitId($this->sku))
        ->assertJsonPath('data.items.1.unit', 'قطعة')
        ->assertJsonPath('data.items.1.sku_unit_id', $piece->id)
        ->assertJsonPath('data.items.1.unit_price', '4.50')
        ->assertJsonPath('data.order_total', '517.50');

    expect($this->inTenant(fn (): int => SkuInventory::query()->findOrFail($this->sku->id)->reserved))->toBe(115);
});

test('D91 D85 a line discount applies to its unit only', function (): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '4.50', 'moq' => 10]);

    ($this->place)(['lines' => [
        ['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 5],
        ['sku_unit_id' => $piece->id, 'quantity' => 10, 'discount' => ['type' => 'amount', 'value' => '0.50']],
    ]])
        ->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '90.00')
        ->assertJsonPath('data.items.1.unit_price', '4.00');
});

test('D91 the quote prices each unit on its own tiers and names the unit', function (): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '4.50', 'moq' => 10], [50 => '4.00']);

    ($this->quote)(['lines' => [['sku_unit_id' => $piece->id, 'quantity' => 60]]])
        ->assertOk()
        ->assertJsonPath('data.lines.0.sku_unit_id', $piece->id)
        ->assertJsonPath('data.lines.0.conversion_factor', 1)
        ->assertJsonPath('data.lines.0.moq', 10)
        ->assertJsonPath('data.lines.0.available', 400)
        ->assertJsonPath('data.lines.0.unit_price', '4.00')
        ->assertJsonPath('data.lines.0.tiers', [['min_quantity' => 50, 'unit_price' => '4.00']]);
});
