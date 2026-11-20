<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;

/*
| BR-PR08, D85: PUT /api/dashboard/orders/{number}/discount (08-api-contract.md §9). The order: 6 cartons of A at
| 90.00 and 10 of B at 85.00, items total 1390.00.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->orderableSku(['code' => 'A-1'])->id => 6, $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب')->id => 10]);
    [$this->lineA, $this->lineB] = $this->inTenant(fn (): array => OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->all());
    $this->body = fn (array $overrides = []): array => [
        'items' => [['order_item_id' => $this->lineA->id, 'type' => 'percent', 'value' => 10], ['order_item_id' => $this->lineB->id, 'type' => 'amount', 'value' => '5']],
        'order' => ['type' => 'amount', 'value' => '50', 'reason' => 'عميل دائم'],
        ...$overrides,
    ];
    $this->put = fn (array $body, ?int $number = null, array $headers = []) => $this->tenantJson('PUT', '/api/dashboard/orders/'.($number ?? $this->order->number).'/discount', $body, headers: $headers);
    $this->asRole = function (TenantUserRole $role): void {
        $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));
    };
});

test('BR-PR08 the merchant discounts lines and the order and gets the order page with the new totals', function (): void {
    $response = ($this->put)(($this->body)())
        ->assertOk()
        ->assertJsonPath('data.items_total', '1286.00')
        ->assertJsonPath('data.discount', ['type' => 'amount', 'value' => '50.00', 'amount' => '50.00', 'reason' => 'عميل دائم'])
        ->assertJsonPath('data.order_total', '1236.00')
        ->assertJsonPath('data.net_total', '1236.00')
        ->assertJsonPath('data.items.0.list_unit_price', '90.00')
        ->assertJsonPath('data.items.0.manual_discount', ['type' => 'percent', 'value' => '10.00'])
        ->assertJsonPath('data.items.0.unit_price', '81.00')
        ->assertJsonPath('data.items.0.line_total', '486.00')
        ->assertJsonPath('data.items.1.unit_price', '80.00')
        ->assertJsonPath('data.history.1.note', 'خصم');

    expectDocumentedShape($response->json('data'), 'DashboardOrderDetail');
});

test('BR-L the discount is audited by the user with the old and new totals', function (): void {
    ($this->put)(($this->body)())->assertOk();

    $log = AuditLog::withoutTenantScope()->where('action', 'order.discount_changed')->sole();

    expect([$log->actor_type, $log->actor_id, $log->entity_id, $log->old_values['order_total'] ?? null, $log->new_values['order_total'] ?? null])
        ->toBe([ActorType::TenantUser, $this->owner->id, $this->order->id, '1390.00', '1236.00']);
});

test('D85 an empty body removes every discount', function (): void {
    ($this->put)(($this->body)())->assertOk();

    ($this->put)(['items' => []])
        ->assertOk()
        ->assertJsonPath('data.discount', null)
        ->assertJsonPath('data.order_total', '1390.00')
        ->assertJsonPath('data.items.0.manual_discount', null);
});

test('D85 the store shows the customer the discount without the reason', function (): void {
    ($this->put)(($this->body)())->assertOk();
    $this->actingAsCustomer($this->inTenant(fn () => $this->order->customer));

    $this->tenantJson('GET', "/api/store/orders/{$this->order->number}")
        ->assertOk()
        ->assertJsonPath('data.discount', ['type' => 'amount', 'value' => '50.00', 'amount' => '50.00'])
        ->assertJsonPath('data.items.0.list_unit_price', '90.00')
        ->assertJsonPath('data.items.0.unit_price', '81.00');
});

test('discount validation', function (array $body, string $field): void {
    ($this->put)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'order_total' => '1390.00']);
})->with([
    'items missing' => [['order' => null], 'items'],
    'items not a list' => [['items' => 'all'], 'items'],
    'a line without its id' => [['items' => [['type' => 'amount', 'value' => '5']]], 'items.0.order_item_id'],
    'a line twice' => [['items' => [['order_item_id' => 1, 'type' => 'amount', 'value' => '5'], ['order_item_id' => 1, 'type' => 'amount', 'value' => '6']]], 'items.0.order_item_id'],
    'an unknown type' => [['items' => [['order_item_id' => 1, 'type' => 'free', 'value' => '5']]], 'items.0.type'],
    'a type without a value' => [['items' => [['order_item_id' => 1, 'type' => 'amount']]], 'items.0.value'],
    'a value without a type' => [['items' => [['order_item_id' => 1, 'value' => '5']]], 'items.0.type'],
    'a zero value' => [['items' => [['order_item_id' => 1, 'type' => 'amount', 'value' => '0']]], 'items.0.value'],
    'three decimals' => [['items' => [['order_item_id' => 1, 'type' => 'amount', 'value' => '5.001']]], 'items.0.value'],
    'a line percent of 100' => [['items' => [['order_item_id' => 1, 'type' => 'percent', 'value' => '100']]], 'items.0.value'],
    'an unknown line key' => [['items' => [['order_item_id' => 1, 'type' => 'amount', 'value' => '5', 'unit_price' => '1']]], 'items.0'],
    'an order discount without a reason' => [['items' => [], 'order' => ['type' => 'amount', 'value' => '5']], 'order.reason'],
    'an order reason too long' => [['items' => [], 'order' => ['type' => 'amount', 'value' => '5', 'reason' => str_repeat('س', 301)]], 'order.reason'],
    'an order percent of 100' => [['items' => [], 'order' => ['type' => 'percent', 'value' => '100', 'reason' => 'هدية']], 'order.value'],
    'an unknown order type' => [['items' => [], 'order' => ['type' => 'gift', 'value' => '5', 'reason' => 'هدية']], 'order.type'],
    'too many lines' => [['items' => array_fill(0, 201, ['order_item_id' => 1])], 'items'],
]);

test('the order discount reason message is in arabic', function (): void {
    $details = ($this->put)(['items' => [], 'order' => ['type' => 'amount', 'value' => '5']])->assertUnprocessable()->json('error.details');

    expect($details['order.reason'] ?? null)->toBe(['اكتب سبب الخصم على الطلب']);
});

test('D85 a line of another order is a validation error on its field', function (): void {
    $other = $this->inTenant(fn () => OrderItem::factory()->create());

    ($this->put)(['items' => [['order_item_id' => $other->id, 'type' => 'amount', 'value' => '5']]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['items.0.order_item_id']]]);
});

test('D85 a discount below one piaster per unit is too large', function (): void {
    ($this->put)(['items' => [['order_item_id' => $this->lineA->id, 'type' => 'amount', 'value' => '90']]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'discount_too_large');
});

test('D85 an order being prepared is locked', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['status' => OrderStatus::Preparing])->save());

    ($this->put)(($this->body)())->assertConflict()->assertJsonPath('error.code', 'order_locked');
});

test('BR-C03 a total below what was collected is refused', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['collected_amount' => '1300.00', 'collection_status' => 'partially_collected'])->save());

    ($this->put)(($this->body)())->assertConflict()->assertJsonPath('error.code', 'below_collected');
});

test('D85 who may discount', function (TenantUserRole $role, int $status): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['max_discount_percent_sales' => '15']);
    ($this->asRole)($role);

    ($this->put)(['items' => [['order_item_id' => $this->lineA->id, 'type' => 'percent', 'value' => '10']]])->assertStatus($status);
})->with([
    'owner' => [TenantUserRole::Owner, 200],
    'manager' => [TenantUserRole::Manager, 200],
    'sales within the cap' => [TenantUserRole::Sales, 200],
    'warehouse' => [TenantUserRole::Warehouse, 403],
    'accountant' => [TenantUserRole::Accountant, 403],
]);

test('D85 a sales user discounts a line up to the store cap', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['max_discount_percent_sales' => '15']);
    ($this->asRole)(TenantUserRole::Sales);

    ($this->put)(['items' => [['order_item_id' => $this->lineA->id, 'type' => 'percent', 'value' => '15']]])->assertOk()->assertJsonPath('data.items.0.unit_price', '76.50');
});

test('D85 a sales user above the store cap is refused', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['max_discount_percent_sales' => '15']);
    ($this->asRole)(TenantUserRole::Sales);

    ($this->put)(['items' => [['order_item_id' => $this->lineA->id, 'type' => 'percent', 'value' => '15.01']]])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'discount_over_limit')
        ->assertJsonPath('error.details.max_percent', '15.00');
});

test('D85 a sales user never discounts the whole order', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['max_discount_percent_sales' => '15']);
    ($this->asRole)(TenantUserRole::Sales);

    ($this->put)(['items' => [], 'order' => ['type' => 'amount', 'value' => '1', 'reason' => 'عميل دائم']])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'discount_over_limit');
});

test('D85 without a cap set a sales user cannot discount', function (): void {
    ($this->asRole)(TenantUserRole::Sales);

    ($this->put)(['items' => [['order_item_id' => $this->lineA->id, 'type' => 'percent', 'value' => '1']]])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'discount_over_limit')
        ->assertJsonPath('error.message', 'غير مسموح لك بإعطاء خصم');
});

test('D55 the same Idempotency-Key twice applies the discount once and replays the answer', function (): void {
    ($this->put)(($this->body)(), headers: ['Idempotency-Key' => 'discount-1'])->assertOk();

    ($this->put)(($this->body)(), headers: ['Idempotency-Key' => 'discount-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(AuditLog::withoutTenantScope()->where('action', 'order.discount_changed')->count())->toBe(1);
});

test('BR-T04 an order number of another tenant is 404 and unchanged there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 6]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->put)(['items' => []], $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'order_total' => $theirs->order_total, 'discount_amount' => '0.00']);
});

test('a guest is refused', function (): void {
    auth('tenant_user')->logout();

    ($this->put)(($this->body)())->assertUnauthorized();
});

test('errors never leak exception details when debug is off', function (): void {
    config(['app.debug' => false]);

    $response = ($this->put)(['items' => [['order_item_id' => $this->lineA->id, 'type' => 'amount', 'value' => '90']]]);

    expect($response->json('error'))->toHaveKeys(['code', 'message'])->not->toHaveKey('exception')
        ->and((string) $response->getContent())->not->toContain('DiscountTooLarge');
});
