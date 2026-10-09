<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TenantSetting;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer($this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']));
    $this->sku = $this->orderableSku(['code' => 'ADW-SCI']);
    $this->address = $this->addressOf($this->customer, ['phone' => null]);
    $this->fillCart($this->customer, [$this->sku->id => 12]);
    $this->checkout = fn (array $body = [], array $headers = []) => $this->tenantJson('POST', '/api/store/checkout', [...['address_id' => $this->address->id], ...$body], headers: $headers);
});

test('BR-PR06 checkout answers 201 with the order detail', function (): void {
    $this->freezeSecond();

    ($this->checkout)(['customer_note' => 'صباحًا'])
        ->assertCreated()
        ->assertExactJson(['data' => ['order' => [
            'number' => 1,
            'status' => 'pending',
            'status_label' => 'قيد الانتظار',
            'placed_at' => now()->toIso8601String(),
            'items_total' => '1020.00',
            'order_total' => '1020.00',
            'collection_status' => 'unpaid',
            'collected_amount' => '0.00',
            'delivery' => [
                'name' => 'مكتبة الأمل',
                'phone' => '+201012345678',
                'address' => ['label' => 'المحل', 'governorate' => 'القاهرة', 'city' => 'مدينة نصر', 'address_line' => '12 شارع عباس العقاد', 'landmark' => null],
            ],
            'customer_note' => 'صباحًا',
            'cancel_reason' => null,
            'cancelled_by' => null,
            'items' => [[
                'sku_id' => $this->sku->id, 'product_name' => 'الأضواء علوم', 'sku_code' => 'ADW-SCI', 'sku_name' => 'الأضواء علوم', 'unit' => 'كرتونة',
                'conversion_factor' => 20, 'quantity' => 12, 'cover_price' => null, 'unit_price' => '85.00', 'discount_percent' => null,
                'tier_min_quantity' => 10, 'line_total' => '1020.00',
            ]],
            'history' => [['from' => null, 'to' => 'pending', 'at' => now()->toIso8601String(), 'by' => 'customer']],
        ]]]);

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 400, 'reserved' => 240]);
    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-L checkout over http is audited with the customer as actor', function (): void {
    ($this->checkout)()->assertCreated();

    expect(AuditLog::withoutTenantScope()->where('action', 'order.created')->sole()->only(['actor_type', 'actor_id']))
        ->toBe(['actor_type' => App\Modules\Audit\Enums\ActorType::Customer, 'actor_id' => $this->customer->id]);
});

test('BR-PR06 a failing line answers 422 checkout_failed with the sku and leaves the cart', function (): void {
    SkuInventory::withoutTenantScope()->whereKey($this->sku->id)->update(['on_hand' => 140]);

    ($this->checkout)()
        ->assertUnprocessable()
        ->assertExactJson(['error' => [
            'code' => 'checkout_failed',
            'message' => 'تعذر إتمام الطلب، راجع العناصر التالية',
            'items' => [['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'requested' => 12, 'available' => 7]],
        ]]);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseHas('cart_items', ['sku_id' => $this->sku->id, 'quantity' => 12]);
});

test('BR-MOV02 under the minimum order value answers 422 with details.mov', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1500.00']);

    ($this->checkout)()
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'checkout_failed')
        ->assertJsonPath('error.details.mov', ['required' => '1500.00', 'current' => '1020.00']);
});

test('D57 an empty cart answers 422 cart_empty', function (): void {
    CartItem::withoutTenantScope()->delete();

    ($this->checkout)()->assertUnprocessable()->assertJsonPath('error.code', 'cart_empty');
});

test('checkout validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/checkout', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'address missing' => [[], 'address_id'],
    'address not a number' => [['address_id' => 'x'], 'address_id'],
    'note too long' => [['address_id' => 1, 'customer_note' => str_repeat('ن', 1001)], 'customer_note'],
]);

test('D57 an address of another customer answers 422 on address_id and orders nothing', function (): void {
    ($this->checkout)(['address_id' => $this->addressOf($this->makeCustomer())->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['address_id']]]);

    $this->assertDatabaseCount('orders', 0);
});

test('BR-T04 an address of another tenant answers 422 on address_id and orders nothing', function (): void {
    $foreign = $this->addressOf($this->makeCustomer(tenant: $this->otherTenant()));

    ($this->checkout)(['address_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['address_id']]]);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id, 'status' => 'active']);
});

test('D55 the same Idempotency-Key twice creates one order and replays it', function (): void {
    $first = ($this->checkout)(headers: ['Idempotency-Key' => 'checkout-1'])->assertCreated();

    ($this->checkout)(headers: ['Idempotency-Key' => 'checkout-1'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first->json());

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'reserved' => 240]);
});

test('without an Idempotency-Key a second checkout finds the cart empty', function (): void {
    ($this->checkout)()->assertCreated();

    ($this->checkout)()->assertUnprocessable()->assertJsonPath('error.code', 'cart_empty');
});

test('BR-CU05 D51 a customer who is not active cannot check out', function (CustomerStatus $status): void {
    $this->customer->forceFill(['status' => $status])->save();

    ($this->checkout)()->assertForbidden()->assertJsonPath('error.code', $status->value);

    $this->assertDatabaseCount('orders', 0);
})->with(['blocked' => [CustomerStatus::Blocked], 'pending approval' => [CustomerStatus::PendingApproval]]);

test('BR-T07 a suspended store takes no order', function (): void {
    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    ($this->checkout)()->assertServiceUnavailable()->assertJsonPath('error.code', 'store_unavailable');
});

test('a guest cannot check out', function (): void {
    auth('customer')->logout();

    ($this->checkout)()->assertUnauthorized();
});

test('Tech §6 a customer session of this store is refused on another store\'s host', function (): void {
    $other = $this->otherTenant();

    $this->tenantJson('POST', '/api/store/checkout', ['address_id' => $this->address->id], $other)->assertUnauthorized();

    expect(Order::withoutTenantScope()->count())->toBe(0);
});

test('BR-N02 the merchant finds the new order in the notifications', function (): void {
    ($this->checkout)()->assertCreated();

    expect(MerchantNotification::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole()->data['order_number'])->toBe(1);
});
