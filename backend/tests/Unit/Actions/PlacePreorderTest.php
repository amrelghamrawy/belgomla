<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\PlacePreorder;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer();
    $this->address = $this->addressOf($this->customer);
    $this->sku = $this->preorderableSku();
    app(TenantContext::class)->set($this->tenant);

    $this->place = fn (?string $note = null): Order => app(PlacePreorder::class)->handle($this->customer, new CheckoutData($this->address->id, $note));
    $this->product = fn (array $attributes): int => Product::query()->whereKey($this->sku->product_id)->update($attributes);
});

test('BR-O14 the pre-order lines of the cart become a pending pre-order, priced at today\'s tiers', function (): void {
    $this->fillPreorderCart($this->customer, [$this->sku->id => 12]);

    $order = ($this->place)('قبل المدارس');

    expect($order->only(['kind', 'status', 'items_total', 'order_total', 'customer_note', 'placed_by_type']))->toBe([
        'kind' => OrderKind::Preorder, 'status' => OrderStatus::Pending, 'items_total' => '1020.00', 'order_total' => '1020.00',
        'customer_note' => 'قبل المدارس', 'placed_by_type' => ActorType::Customer,
    ])
        ->and(OrderItem::query()->where('order_id', $order->id)->sole()->only(['sku_id', 'quantity', 'unit_price', 'reserved', 'converted_quantity']))
        ->toBe(['sku_id' => $this->sku->id, 'quantity' => 12, 'unit_price' => '85.00', 'reserved' => false, 'converted_quantity' => 0]);
});

test('BR-O14 BR-INV01 a pre-order reserves nothing and needs no stock', function (): void {
    $this->fillPreorderCart($this->customer, [$this->sku->id => 50]);

    ($this->place)();

    expect(SkuInventory::query()->find($this->sku->id)?->only(['on_hand', 'reserved']) ?? ['on_hand' => 0, 'reserved' => 0])->toBe(['on_hand' => 0, 'reserved' => 0]);
});

test('BR-O14 only the pre-order lines are taken and cleared; the order lines stay in the cart', function (): void {
    $active = $this->orderableSku();
    $this->fillCart($this->customer, [$active->id => 5]);
    $this->fillPreorderCart($this->customer, [$this->sku->id => 5]);

    $order = ($this->place)();

    expect(OrderItem::query()->where('order_id', $order->id)->pluck('sku_id')->all())->toBe([$this->sku->id])
        ->and(CartItem::query()->get(['sku_id', 'kind'])->map(fn (CartItem $item): array => [$item->sku_id, $item->kind])->all())->toBe([[$active->id, CartLineKind::Order]]);
});

test('BR-O14 an empty pre-order cart is refused like an empty checkout', function (): void {
    $this->fillCart($this->customer, [$this->orderableSku()->id => 5]);

    expect(fn (): Order => ($this->place)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('cart_empty');
    });
});

test('BR-O14 the product must still take pre-orders when the customer confirms', function (array $attributes): void {
    $this->fillPreorderCart($this->customer, [$this->sku->id => 5]);
    ($this->product)($attributes);

    expect(fn (): Order => ($this->place)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'preorder_unavailable']]);
    })
        ->and(Order::query()->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(1);
})->with([
    'pre-orders turned off' => [['preorder_enabled' => false]],
    'the product went active' => [['status' => ProductStatus::Active->value]],
    'the product went inactive' => [['status' => ProductStatus::Inactive->value]],
]);

test('BR-MOQ01 a pre-order line below the MOQ is refused', function (): void {
    $this->fillPreorderCart($this->customer, [$this->sku->id => 4]);

    expect(fn (): Order => ($this->place)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'below_moq', 'moq' => 5]]);
    });
});

test('BR-O14 the cap counts every open pre-order: at the cap passes, one more is refused with what remains', function (): void {
    ($this->product)(['preorder_limit' => 20]);
    $this->placePreorder($this->makeCustomer(), [$this->sku->id => 12]);

    $this->fillPreorderCart($this->customer, [$this->sku->id => 9]);
    $refused = fn (): Order => ($this->place)();

    expect($refused)->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'preorder_limit', 'requested' => 9, 'remaining' => 8]]);
    });

    $this->fillPreorderCart($this->customer, [$this->sku->id => 8]);

    expect(($this->place)()->items()->sole()->quantity)->toBe(8);
});

test('BR-O14 cancelled pre-orders and converted units no longer count against the cap', function (): void {
    ($this->product)(['preorder_limit' => 20]);
    $cancelled = $this->placePreorder($this->makeCustomer(), [$this->sku->id => 15]);
    $cancelled->forceFill(['status' => OrderStatus::Cancelled])->save();
    $partly = $this->placePreorder($this->makeCustomer(), [$this->sku->id => 10]);
    OrderItem::query()->where('order_id', $partly->id)->update(['converted_quantity' => 6]);

    $this->fillPreorderCart($this->customer, [$this->sku->id => 16]);

    expect(($this->place)()->items()->sole()->quantity)->toBe(16);
});

test('BR-C05 a pre-order must fit the customer\'s credit limit', function (): void {
    $this->customer->forceFill(['credit_limit' => '500.00'])->save();
    $this->fillPreorderCart($this->customer, [$this->sku->id => 6]);

    expect(fn (): Order => ($this->place)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->details)->toBe(['credit' => ['limit' => '500.00', 'exposure' => '0.00', 'order_total' => '540.00']]);
    });
});

test('D88 the minimum order value does not apply to a pre-order', function (): void {
    TenantSetting::query()->firstOrFail()->update(['mov_enabled' => true, 'mov_amount' => '5000.00']);
    $this->fillPreorderCart($this->customer, [$this->sku->id => 5]);

    expect(($this->place)()->order_total)->toBe('450.00');
});

test('BR-N02 BR-O04 a pre-order rings new_preorder, starts its history and is audited as a pre-order', function (): void {
    $this->fillPreorderCart($this->customer, [$this->sku->id => 5]);

    $order = ($this->place)();

    expect(MerchantNotification::query()->sole()->only(['type', 'data']))->toEqual([
        'type' => NotificationType::NewPreorder,
        'data' => ['order_number' => $order->number, 'customer_name' => $order->delivery_name, 'order_total' => '450.00'],
    ])
        ->and(OrderStatusHistory::query()->sole()->only(['from_status', 'to_status', 'actor_type', 'actor_id']))
        ->toBe(['from_status' => null, 'to_status' => OrderStatus::Pending, 'actor_type' => ActorType::Customer, 'actor_id' => $this->customer->id])
        ->and(AuditLog::query()->where('action', 'order.created')->sole()->new_values)->toMatchArray(['number' => $order->number, 'kind' => 'preorder', 'order_total' => '450.00']);
});

test('BR-O09 a pre-order takes the next order number of the store', function (): void {
    $order = $this->placeOrder($this->customer, [$this->orderableSku()->id => 5]);
    $this->fillPreorderCart($this->customer, [$this->sku->id => 5]);

    expect(($this->place)()->number)->toBe($order->number + 1);
});

test('BR-O14 BR-PR14 a pre-order is priced at the customer\'s own price, indicatively', function (): void {
    $customer = $this->makeCustomer();
    $sku = $this->preorderableSku(name: 'أطلس ٢٠٢٧');
    CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => $sku->id, 'unit_price' => '66.00']);

    $order = $this->placePreorder($customer, [$sku->id => 5]);
    app(TenantContext::class)->set($this->tenant);

    expect(OrderItem::query()->where('order_id', $order->id)->sole()->only(['unit_price', 'price_source']))->toBe(['unit_price' => '66.00', 'price_source' => PriceSource::Customer])
        ->and($order->order_total)->toBe('330.00');
});
