<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Actions\PlaceOrderForCustomer;
use App\Modules\Orders\Data\MerchantOrderData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\CustomerCannotOrder;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->sku = $this->orderableSku(['code' => 'ADW-SCI', 'cover_price' => '120.00']);
    $this->address = $this->addressOf($this->customer, ['label' => 'المحل', 'city' => 'الدقي', 'phone' => '+201198765432']);
    app(TenantContext::class)->set($this->tenant);

    $this->place = fn (array $lines = [], array $data = []): Order => app(PlaceOrderForCustomer::class)->handle(new MerchantOrderData(...[
        'customerId' => $this->customer->id,
        'addressId' => $this->address->id,
        'lines' => $lines === [] ? [['sku_id' => $this->sku->id, 'quantity' => 12]] : $lines,
        ...$data,
    ]), $this->owner->id);
    $this->expectNothingWritten = function (): void {
        expect(Order::query()->count())->toBe(0)
            ->and(OrderItem::query()->count())->toBe(0)
            ->and(OrderStatusHistory::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0)
            ->and(SkuInventory::query()->findOrFail($this->sku->id)->reserved)->toBe(0);
    };
    $this->message = fn (): ?OutboundMessage => OutboundMessage::query()->where('purpose', MessagePurpose::OrderStatus)->latest('id')->first();
});

test('BR-O11 creates a pending order with the snapshots, the reservation and the note, placed by the user', function (): void {
    $order = ($this->place)(data: ['note' => 'طلب بالتليفون']);

    expect($order->refresh()->only(['number', 'customer_id', 'status', 'order_total', 'delivery_name', 'delivery_phone', 'customer_note', 'placed_by_type', 'placed_by_id']))->toBe([
        'number' => 1,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Pending,
        'order_total' => '1020.00',
        'delivery_name' => 'مكتبة الأمل',
        'delivery_phone' => '+201198765432',
        'customer_note' => 'طلب بالتليفون',
        'placed_by_type' => ActorType::TenantUser,
        'placed_by_id' => $this->owner->id,
    ])
        ->and(OrderItem::query()->sole()->only(['sku_code', 'unit_name', 'quantity', 'quantity_base', 'unit_price', 'tier_min_quantity', 'line_total']))->toBe([
            'sku_code' => 'ADW-SCI', 'unit_name' => 'كرتونة', 'quantity' => 12, 'quantity_base' => 240, 'unit_price' => '85.00', 'tier_min_quantity' => 10, 'line_total' => '1020.00',
        ])
        ->and(SkuInventory::query()->findOrFail($this->sku->id)->reserved)->toBe(240);
});

test('BR-O04 BR-O11 the history records the creation as the dashboard user', function (): void {
    $order = ($this->place)();

    expect(OrderStatusHistory::query()->sole()->only(['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id']))->toBe([
        'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id,
    ]);
});

test('BR-O11 BR-L the creation is audited as order.created with placed_by', function (): void {
    $order = ($this->place)();

    expect(AuditLog::query()->sole()->only(['action', 'entity_id', 'new_values']))->toEqual([
        'action' => 'order.created',
        'entity_id' => $order->id,
        'new_values' => ['number' => 1, 'status' => 'pending', 'order_total' => '1020.00', 'lines' => 1, 'placed_by' => ['type' => 'tenant_user', 'id' => $this->owner->id]],
    ]);
});

test('BR-O11 the merchant made it, so no new_order notification is written', function (): void {
    ($this->place)();

    expect(MerchantNotification::query()->count())->toBe(0);
});

test('BR-O11 several lines are priced each at its own tier', function (): void {
    $other = $this->orderableSku(['moq' => 1, 'base_price' => '33.50'], name: 'كراسة');

    $order = ($this->place)([['sku_id' => $this->sku->id, 'quantity' => 6], ['sku_id' => $other->id, 'quantity' => 3]]);

    expect($order->order_total)->toBe('640.50')
        ->and(OrderItem::query()->orderBy('id')->pluck('line_total')->all())->toBe(['540.00', '100.50']);
});

test('BR-O11 BR-PR06 the same failures as checkout roll everything back and name the sku', function (Closure $break, array $item): void {
    $break($this->sku);

    expect(fn () => ($this->place)())->toThrow(function (CheckoutFailed $e) use ($item): void {
        expect($e->errorCode)->toBe('checkout_failed')
            ->and($e->items)->toBe([['sku_id' => $this->sku->id, ...$item]]);
    });

    ($this->expectNothingWritten)();
})->with([
    'inactive product' => [fn ($sku) => Product::query()->whereKey($sku->product_id)->update(['status' => ProductStatus::Inactive]), ['reason' => 'product_inactive']],
    'below moq' => [fn ($sku) => $sku->forceFill(['moq' => 15])->save(), ['reason' => 'below_moq', 'moq' => 15]],
    'not enough stock' => [fn ($sku) => SkuInventory::query()->whereKey($sku->id)->update(['on_hand' => 230]), ['reason' => 'insufficient_stock', 'requested' => 12, 'available' => 11]],
]);

test('BR-O11 BR-C05 the credit limit is enforced like at checkout', function (): void {
    $this->customer->forceFill(['credit_limit' => '1000.00'])->save();

    expect(fn () => ($this->place)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([])
            ->and($e->details)->toBe(['credit' => ['limit' => '1000.00', 'exposure' => '0.00', 'order_total' => '1020.00']]);
    });

    ($this->expectNothingWritten)();
});

test('D84 the minimum order value is enforced without the override', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1500.00']);

    expect(fn () => ($this->place)())->toThrow(function (CheckoutFailed $e): void {
        expect($e->details)->toBe(['mov' => ['required' => '1500.00', 'current' => '1020.00']]);
    });

    ($this->expectNothingWritten)();
});

test('D84 the override lets an order under the minimum order value through and is audited', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1500.00']);

    $order = ($this->place)(data: ['overrideMov' => true]);

    expect($order->order_total)->toBe('1020.00')
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['mov_overridden' => ['required' => '1500.00', 'current' => '1020.00']]);
});

test('D84 an override that was not needed is not recorded', function (): void {
    ($this->place)(data: ['overrideMov' => true]);

    expect(AuditLog::query()->sole()->new_values)->not->toHaveKey('mov_overridden');
});

test('D84 the override never skips the moq, the stock or the credit limit', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1500.00']);
    $this->customer->forceFill(['credit_limit' => '100.00'])->save();

    expect(fn () => ($this->place)([['sku_id' => $this->sku->id, 'quantity' => 30]], ['overrideMov' => true]))->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'requested' => 30, 'available' => 20]])
            ->and($e->details)->toHaveKey('credit')->not->toHaveKey('mov');
    });
});

test('BR-O11 a blocked customer gets 409 customer_blocked', function (): void {
    $this->customer->forceFill(['status' => CustomerStatus::Blocked])->save();

    expect(fn () => ($this->place)())->toThrow(function (CustomerCannotOrder $e): void {
        expect($e->errorCode)->toBe('customer_blocked')
            ->and($e->status)->toBe(409)
            ->and($e->details)->toBe(['status' => 'blocked']);
    });

    ($this->expectNothingWritten)();
});

test('BR-O11 a customer who has not activated yet gets 409 customer_not_active', function (CustomerStatus $status): void {
    $this->customer->forceFill(['status' => $status])->save();

    expect(fn () => ($this->place)())->toThrow(function (CustomerCannotOrder $e) use ($status): void {
        expect($e->errorCode)->toBe('customer_not_active')->and($e->details)->toBe(['status' => $status->value]);
    });
})->with(['invited' => [CustomerStatus::Invited], 'pending approval' => [CustomerStatus::PendingApproval]]);

test('BR-CU03 an address of another customer is refused with 422 on address_id', function (): void {
    $theirs = CustomerAddress::factory()->create();

    expect(fn () => ($this->place)(data: ['addressId' => $theirs->id]))->toThrow(function (CheckoutFailed $e): void {
        expect($e->status)->toBe(422)
            ->and($e->errorCode)->toBe('validation_failed')
            ->and($e->details)->toHaveKey('address_id');
    });

    ($this->expectNothingWritten)();
});

test('BR-T04 a customer or a sku of another tenant is refused as a field error', function (): void {
    $other = $this->otherTenant();
    $theirCustomer = $this->makeCustomer(tenant: $other);
    $theirSku = $this->orderableSku(tenant: $other);
    app(TenantContext::class)->set($this->tenant);

    expect(fn () => ($this->place)(data: ['customerId' => $theirCustomer->id]))->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('validation_failed')->and($e->details)->toHaveKey('customer_id');
    })
        ->and(fn () => ($this->place)([['sku_id' => $this->sku->id, 'quantity' => 5], ['sku_id' => $theirSku->id, 'quantity' => 5]]))->toThrow(function (CheckoutFailed $e): void {
            expect($e->details)->toHaveKey('lines.1.sku_id');
        });

    ($this->expectNothingWritten)();
});

test('BR-O11 the customer is told with the placed message only when asked', function (bool $notify, bool $sent): void {
    ($this->place)(data: ['notifyCustomer' => $notify]);

    expect(($this->message)() !== null)->toBe($sent);
})->with(['asked' => [true, true], 'not asked' => [false, false]]);

test('BR-O11 the placed message uses the default words and is tied to the order', function (): void {
    $order = ($this->place)(data: ['notifyCustomer' => true]);

    expect(($this->message)()?->only(['to_phone', 'order_id']))->toBe(['to_phone' => '+201012345678', 'order_id' => $order->id])
        ->and(($this->message)()?->body)->toBe('أهلًا مكتبة الأمل، تم تسجيل طلبك رقم 1 من مكتبة النور بإجمالي 1020.00. سنبلغك عند تأكيده.');
});

test('BR-N03 a customer who opted out of order messages is not told even when asked', function (): void {
    $this->customer->forceFill(['order_notifications_opt_out' => true])->save();

    ($this->place)(data: ['notifyCustomer' => true]);

    expect(($this->message)())->toBeNull();
});

test('BR-O09 merchant orders and storefront orders share one number sequence', function (): void {
    $first = $this->placeOrder($this->customer, [$this->sku->id => 5]);
    app(TenantContext::class)->set($this->tenant);

    $second = ($this->place)();

    expect([$first->number, $second->number])->toBe([1, 2]);
});

test('D85 discounts from the form apply to the matching lines by sku, audited once, without their own message', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['discount'])]);
    $other = $this->orderableSku(['code' => 'OTHER-1'], name: 'كتاب آخر');

    $order = ($this->place)([['sku_id' => $this->sku->id, 'quantity' => 12], ['sku_id' => $other->id, 'quantity' => 6]], [
        'lineDiscounts' => [$other->id => ['type' => App\Modules\Pricing\Enums\DiscountType::Amount, 'value' => '10.00']],
        'discountType' => App\Modules\Pricing\Enums\DiscountType::Percent,
        'discountValue' => '5.00',
        'discountReason' => 'بالتليفون',
    ]);

    expect(OrderItem::query()->where('order_id', $order->id)->orderBy('id')->pluck('unit_price')->all())->toBe(['85.00', '80.00'])
        ->and($order->refresh()->only(['items_total', 'discount_amount', 'order_total']))->toBe(['items_total' => '1500.00', 'discount_amount' => '75.00', 'order_total' => '1425.00'])
        ->and(AuditLog::query()->where('action', 'order.discount_changed')->count())->toBe(1)
        ->and(($this->message)())->toBeNull();
});

test('BR-O11 BR-PR14 a merchant\'s order for a customer is priced at that customer\'s prices', function (): void {
    $group = CustomerGroup::factory()->create(['discount_percent' => '50.00']);
    Customer::query()->whereKey($this->customer->id)->update(['customer_group_id' => $group->id]);

    ($this->place)();

    expect(OrderItem::query()->sole()->only(['unit_price', 'price_source']))->toBe(['unit_price' => '45.00', 'price_source' => PriceSource::Group]);
});
