<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->sku = $this->orderableSku(['code' => 'ADW-SCI', 'cover_price' => '120.00']);
    $this->address = $this->addressOf($this->customer, ['label' => 'المحل', 'city' => 'الدقي', 'phone' => '+201198765432']);
    app(TenantContext::class)->set($this->tenant);

    $this->checkout = fn (?int $addressId = null, ?string $note = null): Order => app(Checkout::class)->handle($this->customer, new CheckoutData($addressId ?? $this->address->id, $note));
    $this->reserved = fn (): int => SkuInventory::query()->findOrFail($this->sku->id)->reserved;
    $this->expectNothingWritten = function (int $reserved = 0): void {
        expect(Order::query()->count())->toBe(0)
            ->and(OrderItem::query()->count())->toBe(0)
            ->and(OrderStatusHistory::query()->count())->toBe(0)
            ->and(MerchantNotification::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0)
            ->and(($this->reserved)())->toBe($reserved)
            ->and(CartItem::query()->count())->toBeGreaterThan(0);
    };
});

test('BR-PR06 checkout creates a pending order with the totals, the delivery snapshot and the note', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);
    $this->freezeSecond();

    $order = ($this->checkout)(note: 'التوصيل صباحًا');

    expect($order->refresh()->only(['number', 'customer_id', 'status', 'items_total', 'order_total', 'collected_amount', 'collection_status', 'delivery_name', 'delivery_phone', 'customer_note']))->toBe([
        'number' => 1,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Pending,
        'items_total' => '1020.00',
        'order_total' => '1020.00',
        'collected_amount' => '0.00',
        'collection_status' => CollectionStatus::Unpaid,
        'delivery_name' => 'مكتبة الأمل',
        'delivery_phone' => '+201198765432',
        'customer_note' => 'التوصيل صباحًا',
    ])
        ->and($order->delivery_address)->toEqual(['label' => 'المحل', 'governorate' => 'القاهرة', 'city' => 'الدقي', 'address_line' => '12 شارع عباس العقاد', 'landmark' => null])
        ->and($order->placed_at->equalTo(now()))->toBeTrue()
        ->and($order->confirmed_at)->toBeNull();
});

test('BR-PR07 every order line snapshots the product, sku, unit and the price applied', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);

    $order = ($this->checkout)();

    expect(OrderItem::query()->sole()->only([
        'order_id', 'sku_id', 'product_id', 'product_name', 'sku_code', 'sku_name', 'unit_name', 'conversion_factor',
        'quantity', 'quantity_base', 'cover_price', 'unit_price', 'discount_percent', 'tier_min_quantity', 'line_total',
    ]))->toBe([
        'order_id' => $order->id,
        'sku_id' => $this->sku->id,
        'product_id' => $this->sku->product_id,
        'product_name' => 'الأضواء علوم',
        'sku_code' => 'ADW-SCI',
        'sku_name' => 'الأضواء علوم',
        'unit_name' => 'كرتونة',
        'conversion_factor' => 20,
        'quantity' => 12,
        'quantity_base' => 240,
        'cover_price' => '120.00',
        'unit_price' => '85.00',
        'discount_percent' => '29.17',
        'tier_min_quantity' => 10,
        'line_total' => '1020.00',
    ]);
});

test('BR-O10 without an address phone the customer phone is the delivery phone', function (): void {
    $address = $this->addressOf($this->customer, ['phone' => null, 'is_default' => false]);
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    expect(($this->checkout)($address->id)->delivery_phone)->toBe('+201012345678');
});

test('BR-INV03 checkout reserves quantity × conversion factor without a movement', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);

    ($this->checkout)();

    expect(SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 400, 'reserved' => 240])
        ->and(InventoryMovement::query()->where('type', 'sale')->count())->toBe(0);
});

test('BR-O04 checkout records the creation in the history as the customer', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    $order = ($this->checkout)();

    expect(OrderStatusHistory::query()->sole()->only(['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
        'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending, 'actor_type' => ActorType::Customer, 'actor_id' => $this->customer->id, 'note' => null,
    ]);
});

test('BR-CU06 checkout empties the cart', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    ($this->checkout)();

    expect(CartItem::query()->count())->toBe(0);
});

test('BR-N02 checkout notifies the merchant of the new order', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    $order = ($this->checkout)();

    expect(MerchantNotification::query()->sole()->only(['tenant_user_id', 'type', 'data', 'read_at']))->toEqual([
        'tenant_user_id' => null,
        'type' => NotificationType::NewOrder,
        'data' => ['order_number' => $order->number, 'customer_name' => 'مكتبة الأمل', 'order_total' => '450.00'],
        'read_at' => null,
    ]);
});

test('BR-L checkout is audited as order.created', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    $order = ($this->checkout)();

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'order.created',
        'entity_type' => 'orders',
        'entity_id' => $order->id,
        'old_values' => null,
        'new_values' => ['number' => 1, 'status' => 'pending', 'order_total' => '450.00', 'lines' => 1],
    ]);
});

test('BR-O08 several lines add up to the order total, each at its own price', function (): void {
    $other = $this->orderableSku(['moq' => 1, 'base_price' => '33.50'], name: 'كراسة');
    $this->fillCart($this->customer, [$this->sku->id => 6, $other->id => 3]);

    $order = ($this->checkout)();

    expect($order->order_total)->toBe('640.50')
        ->and(OrderItem::query()->orderBy('id')->pluck('line_total')->all())->toBe(['540.00', '100.50']);
});

test('BR-O09 order numbers are sequential per tenant and every tenant starts at 1', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $first = ($this->checkout)();
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $second = ($this->checkout)();

    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);

    expect([$first->number, $second->number, $theirs->number])->toBe([1, 2, 1]);
});

test('BR-PR07 BR-O05 later catalog and price changes never rewrite the order', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);
    $order = ($this->checkout)();

    Product::query()->whereKey($this->sku->product_id)->update(['name' => 'اسم جديد']);
    $this->sku->forceFill(['code' => 'NEW-CODE', 'base_price' => '200.00'])->save();
    SkuPriceTier::query()->where('sku_id', $this->sku->id)->update(['unit_price' => '150.00']);

    expect(OrderItem::query()->sole()->only(['product_name', 'sku_code', 'unit_price', 'line_total']))->toBe(['product_name' => 'الأضواء علوم', 'sku_code' => 'ADW-SCI', 'unit_price' => '85.00', 'line_total' => '1020.00'])
        ->and($order->refresh()->order_total)->toBe('1020.00');
});

test('BR-PR06 the price comes from the current tiers, not what the cart showed', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);
    SkuPriceTier::query()->where('sku_id', $this->sku->id)->update(['unit_price' => '80.00']);

    expect(($this->checkout)()->order_total)->toBe('960.00');
});

test('BR-PR06 a failing line rolls everything back and names the sku', function (Closure $break, array $item): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);
    $break($this->sku);
    $reserved = ($this->reserved)();

    expect(fn () => ($this->checkout)())->toThrow(function (CheckoutFailed $e) use ($item): void {
        expect($e->errorCode)->toBe('checkout_failed')
            ->and($e->status)->toBe(422)
            ->and($e->items)->toBe([['sku_id' => $this->sku->id, ...$item]]);
    });

    ($this->expectNothingWritten)($reserved);
})->with([
    'product inactive' => [fn ($sku) => Product::query()->whereKey($sku->product_id)->update(['status' => ProductStatus::Inactive]), ['reason' => 'product_inactive']],
    'product coming soon' => [fn ($sku) => Product::query()->whereKey($sku->product_id)->update(['status' => ProductStatus::ComingSoon]), ['reason' => 'product_inactive']],
    'moq raised above the quantity' => [fn ($sku) => $sku->forceFill(['moq' => 15])->save(), ['reason' => 'below_moq', 'moq' => 15]],
    'stock dropped below the quantity' => [fn ($sku) => SkuInventory::query()->whereKey($sku->id)->update(['on_hand' => 230]), ['reason' => 'insufficient_stock', 'requested' => 12, 'available' => 11]],
    'stock reserved by others' => [fn ($sku) => SkuInventory::query()->whereKey($sku->id)->update(['reserved' => 300]), ['reason' => 'insufficient_stock', 'requested' => 12, 'available' => 5]],
]);

test('BR-MOV02 an order under the minimum order value rolls everything back', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1500.00']);
    $this->fillCart($this->customer, [$this->sku->id => 12]);

    expect(fn () => ($this->checkout)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([])
            ->and($e->details)->toBe(['mov' => ['required' => '1500.00', 'current' => '1020.00']]);
    });

    ($this->expectNothingWritten)();
});

test('D57 an empty cart cannot be checked out', function (): void {
    expect(fn () => ($this->checkout)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('cart_empty')->and($e->status)->toBe(422);
    });

    expect(Order::query()->count())->toBe(0);
});

test('D57 an address that is not an active address of the customer is refused', function (Closure $address): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    expect(fn () => ($this->checkout)($address($this->customer)->id))->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('validation_failed')->and($e->details)->toHaveKey('address_id');
    });

    ($this->expectNothingWritten)();
})->with([
    'another customer\'s' => [fn () => CustomerAddress::factory()->create()],
    'archived' => [fn ($customer) => CustomerAddress::factory()->for($customer)->archived()->create()],
]);

test('BR-CU05 a customer who is no longer active cannot check out', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $this->customer->newQuery()->whereKey($this->customer->id)->update(['status' => CustomerStatus::Blocked]);

    expect(fn () => ($this->checkout)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('blocked')->and($e->status)->toBe(403);
    });

    ($this->expectNothingWritten)();
});

test('BR-PR06 step 1 a suspended tenant takes no order', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    expect(fn () => ($this->checkout)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('store_unavailable')->and($e->status)->toBe(503);
    });

    ($this->expectNothingWritten)();
});
