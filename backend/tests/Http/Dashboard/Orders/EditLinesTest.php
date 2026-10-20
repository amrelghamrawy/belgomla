<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;

/*
| BR-O12, D87: PUT /api/dashboard/orders/{number}/lines (08-api-contract.md §9). The order: 6 cartons of A at 90.00
| and 10 of B at 85.00, items total 1390.00; C is in stock and not on it.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->skuA = $this->orderableSku(['code' => 'A-1']);
    $this->skuB = $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب');
    $this->skuC = $this->orderableSku(['code' => 'C-1'], name: 'كتاب ج');
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->skuA->id => 6, $this->skuB->id => 10]);
    [$this->lineA, $this->lineB] = $this->inTenant(fn (): array => OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->all());
    // +1 SKU (C), −1 line (B), a quantity change (A 6 → 12).
    $this->body = fn (array $overrides = []): array => [
        'lines' => [
            ['order_item_id' => $this->lineA->id, 'sku_id' => $this->skuA->id, 'quantity' => 12],
            ['sku_id' => $this->skuC->id, 'quantity' => 5],
        ],
        'note' => 'العميل اتصل',
        ...$overrides,
    ];
    $this->put = fn (array $body, ?int $number = null, array $headers = []) => $this->tenantJson('PUT', '/api/dashboard/orders/'.($number ?? $this->order->number).'/lines', $body, headers: $headers);
    $this->asRole = function (TenantUserRole $role): void {
        $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));
    };
    $this->reserved = fn (): array => $this->inTenant(fn (): array => SkuInventory::query()->whereIn('sku_id', [$this->skuA->id, $this->skuB->id, $this->skuC->id])->orderBy('sku_id')->pluck('reserved')->all());
});

test('BR-O12 the merchant edits the lines and gets the order page with the changes and the new totals', function (): void {
    $response = ($this->put)(($this->body)())
        ->assertOk()
        ->assertJsonPath('data.items_total', '1470.00')
        ->assertJsonPath('data.order_total', '1470.00')
        ->assertJsonPath('data.items.0.quantity', 12)
        ->assertJsonPath('data.items.0.unit_price', '85.00')
        ->assertJsonPath('data.items.1.sku_code', 'B-1')
        ->assertJsonPath('data.items.1.removed_at', fn (?string $at): bool => $at !== null)
        ->assertJsonPath('data.items.2.sku_code', 'C-1')
        ->assertJsonPath('data.changes.0.change', 'removed')
        ->assertJsonPath('data.changes.1', fn (array $change): bool => $change['change'] === 'quantity' && $change['old_quantity'] === 6 && $change['new_quantity'] === 12 && $change['actor_id'] === $this->owner->id)
        ->assertJsonPath('data.changes.2.product_name', 'كتاب ج')
        ->assertJsonPath('data.history.1.note', 'تعديل الطلب: العميل اتصل')
        ->assertJsonPath('meta.below_mov', null);

    expectDocumentedShape($response->json('data'), 'DashboardOrderDetail');
    expectDocumentedShape($response->json('data.changes.0'), 'DashboardOrderItemChange');
    expect(($this->reserved)())->toBe([240, 0, 100]);
});

test('BR-MOV02 an order edited below the minimum order value is saved and the shortfall reported', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '2000.00']);

    ($this->put)(($this->body)())->assertOk()->assertJsonPath('meta.below_mov', ['required' => '2000.00', 'current' => '1470.00']);
});

test('BR-L the edit is audited by the user', function (): void {
    ($this->put)(($this->body)())->assertOk();

    $log = AuditLog::withoutTenantScope()->where('action', 'order.lines_changed')->sole();

    expect([$log->actor_type, $log->actor_id, $log->entity_id, $log->old_values['order_total'] ?? null, $log->new_values['order_total'] ?? null])
        ->toBe([ActorType::TenantUser, $this->owner->id, $this->order->id, '1390.00', '1470.00']);
});

test('BR-O12 the customer sees the delivered and removed lines and the changes, without who made them', function (): void {
    ($this->put)(($this->body)())->assertOk();
    $this->actingAsCustomer($this->inTenant(fn () => $this->order->customer));

    $response = $this->tenantJson('GET', "/api/store/orders/{$this->order->number}")
        ->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.items.1.removed_at', fn (?string $at): bool => $at !== null)
        ->assertJsonCount(3, 'data.changes')
        ->assertJsonMissingPath('data.changes.0.actor_id');

    expectDocumentedShape($response->json('data'), 'OrderDetail');
    expectDocumentedShape($response->json('data.changes.0'), 'OrderItemChange');
});

test('edit validation', function (array $body, string $field): void {
    ($this->put)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'order_total' => '1390.00']);
})->with([
    'lines missing' => [[], 'lines'],
    'no lines' => [['lines' => []], 'lines'],
    'a line without a sku' => [['lines' => [['quantity' => 5]]], 'lines.0.sku_id'],
    'a line without a quantity' => [['lines' => [['sku_id' => 1]]], 'lines.0.quantity'],
    'a zero quantity' => [['lines' => [['sku_id' => 1, 'quantity' => 0]]], 'lines.0.quantity'],
    'a fraction' => [['lines' => [['sku_id' => 1, 'quantity' => 1.5]]], 'lines.0.quantity'],
    'a sku twice' => [['lines' => [['sku_id' => 1, 'quantity' => 5], ['sku_id' => 1, 'quantity' => 6]]], 'lines.0.sku_id'],
    'a line id twice' => [['lines' => [['order_item_id' => 1, 'sku_id' => 1, 'quantity' => 5], ['order_item_id' => 1, 'sku_id' => 2, 'quantity' => 6]]], 'lines.0.order_item_id'],
    'an unknown line key' => [['lines' => [['sku_id' => 1, 'quantity' => 5, 'unit_price' => '1']]], 'lines.0'],
    'too many lines' => [['lines' => array_map(fn (int $i): array => ['sku_id' => $i, 'quantity' => 5], range(1, 201))], 'lines'],
    'a note too long' => [['lines' => [['sku_id' => 1, 'quantity' => 5]], 'note' => str_repeat('س', 251)], 'note'],
]);

test('BR-O12 a line that is not a live line of the order is a validation error on its field', function (): void {
    $other = $this->inTenant(fn () => OrderItem::factory()->create());

    ($this->put)(['lines' => [['order_item_id' => $other->id, 'sku_id' => $other->sku_id, 'quantity' => 5]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details', ['lines.0.order_item_id' => ['البند غير موجود في هذا الطلب']]);
});

test('BR-PR06 a stock shortfall answers checkout_failed and changes nothing', function (): void {
    ($this->put)(['lines' => [['order_item_id' => $this->lineA->id, 'sku_id' => $this->skuA->id, 'quantity' => 21]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'checkout_failed')
        ->assertJsonPath('error.items', [['sku_id' => $this->skuA->id, 'reason' => 'insufficient_stock', 'requested' => 21, 'available' => 20]]);

    expect(($this->reserved)())->toBe([120, 200, 0]);
});

test('BR-O12 an order being prepared is locked', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['status' => OrderStatus::Preparing])->save());

    ($this->put)(($this->body)())->assertConflict()->assertJsonPath('error.code', 'order_locked')->assertJsonPath('error.details.status', 'preparing');
});

test('BR-C03 a total below what was collected is refused', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['collected_amount' => '1450.00', 'collection_status' => 'partially_collected'])->save());

    ($this->put)(['lines' => [['order_item_id' => $this->lineA->id, 'sku_id' => $this->skuA->id, 'quantity' => 6]]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'below_collected');
});

test('BR-T06 who may edit the lines of an order', function (TenantUserRole $role, int $status): void {
    ($this->asRole)($role);

    ($this->put)(($this->body)())->assertStatus($status);
})->with([
    'owner' => [TenantUserRole::Owner, 200],
    'manager' => [TenantUserRole::Manager, 200],
    'sales' => [TenantUserRole::Sales, 200],
    'warehouse' => [TenantUserRole::Warehouse, 403],
    'accountant' => [TenantUserRole::Accountant, 403],
]);

test('D55 the same Idempotency-Key twice edits once and replays the answer', function (): void {
    ($this->put)(($this->body)(), headers: ['Idempotency-Key' => 'edit-1'])->assertOk();

    ($this->put)(($this->body)(), headers: ['Idempotency-Key' => 'edit-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(AuditLog::withoutTenantScope()->where('action', 'order.lines_changed')->count())->toBe(1)
        ->and(($this->reserved)())->toBe([240, 0, 100]);
});

test('BR-T04 an order number of another tenant is 404 and unchanged there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 6]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->put)(['lines' => [['sku_id' => $this->skuC->id, 'quantity' => 5]]], $theirs->number)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'order_total' => $theirs->order_total]);
    $this->assertDatabaseCount('order_item_changes', 0);
});

test('a guest is refused', function (): void {
    auth('tenant_user')->logout();

    ($this->put)(($this->body)())->assertUnauthorized();
});

test('errors never leak exception details when debug is off', function (): void {
    config(['app.debug' => false]);
    $this->inTenant(fn () => $this->order->forceFill(['status' => OrderStatus::Preparing])->save());

    $response = ($this->put)(($this->body)());

    expect($response->json('error'))->toHaveKeys(['code', 'message'])->not->toHaveKey('exception')
        ->and((string) $response->getContent())->not->toContain('OrderEditRefused');
});

test('BR-O12 the quote of an edit counts what the order holds as available', function (): void {
    $this->tenantJson('POST', '/api/dashboard/orders/quote', [
        'customer_id' => $this->order->customer_id,
        'order' => $this->order->number,
        'lines' => [['sku_id' => $this->skuA->id, 'quantity' => 20]],
    ])
        ->assertOk()
        ->assertJsonPath('data.lines.0.available', 20)
        ->assertJsonPath('data.lines.0.problem', null);
});

test('BR-O12 the quote of an edit refuses an order of another customer', function (): void {
    $this->tenantJson('POST', '/api/dashboard/orders/quote', [
        'customer_id' => $this->makeCustomer(['phone' => '+201099999999'])->id,
        'order' => $this->order->number,
        'lines' => [['sku_id' => $this->skuA->id, 'quantity' => 5]],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.details', ['order' => ['الطلب غير موجود']]);
});
