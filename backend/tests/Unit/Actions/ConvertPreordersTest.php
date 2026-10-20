<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Actions\ConvertPreorders;
use App\Modules\Orders\Data\ConvertedPreorders;
use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PreorderState;
use App\Modules\Orders\Exceptions\PreordersNotConvertible;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

/*
| BR-O14, D88 with the log driver and Queue::fake(): the customer's `preorder_converted` text is an `outbound_messages`
| row. The SKU is sold in cartons of 20, MOQ 5, 90.00 and 85.00 from 10 cartons when pre-ordered.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->makeOwner();
    $this->sku = $this->preorderableSku();
    [$this->first, $this->second, $this->third] = [$this->makeCustomer(['name' => 'الأول']), $this->makeCustomer(['name' => 'الثاني']), $this->makeCustomer(['name' => 'الثالث'])];
    $this->preorders = [];

    foreach ([$this->first, $this->second, $this->third] as $customer) {
        $this->preorders[] = $this->placePreorder($customer, [$this->sku->id => 10]);
        $this->travel(1)->minutes();
    }

    app(TenantContext::class)->set($this->tenant);
    AuditLog::query()->delete();
    MerchantNotification::query()->delete();

    $this->activate = function (int $cartons): void {
        Product::query()->whereKey($this->sku->product_id)->update(['status' => ProductStatus::Active->value]);
        SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => $cartons * 20]);
    };
    $this->convert = fn (): ConvertedPreorders => app(ConvertPreorders::class)->handle(Product::query()->findOrFail($this->sku->product_id), $this->owner->id);
    $this->line = fn (Order $order): OrderItem => OrderItem::query()->where('order_id', $order->id)->sole();
    $this->convertedFrom = fn (Order $preorder): ?Order => Order::query()->where('converted_from_id', $preorder->id)->first();
});

test('BR-O14 nothing is converted while the product is still coming soon', function (): void {
    expect(fn (): ConvertedPreorders => ($this->convert)())->toThrow(function (PreordersNotConvertible $e): void {
        expect($e->errorCode)->toBe('product_not_active')
            ->and($e->status)->toBe(409)
            ->and($e->details)->toBe(['status' => 'coming_soon']);
    })
        ->and(Order::query()->where('kind', OrderKind::Order)->count())->toBe(0);
});

test('D88 stock for one and a half pre-orders: the oldest converted in full, the next in part, the last untouched', function (): void {
    ($this->activate)(15);

    $result = ($this->convert)();

    $first = ($this->convertedFrom)($this->preorders[0]);
    $second = ($this->convertedFrom)($this->preorders[1]);

    expect($result->orders)->toBe([
        ['number' => $first?->number, 'preorder_number' => $this->preorders[0]->number, 'quantity' => 10, 'order_total' => '850.00'],
        ['number' => $second?->number, 'preorder_number' => $this->preorders[1]->number, 'quantity' => 5, 'order_total' => '450.00'],
    ])
        ->and($result->partially)->toBe(1)
        ->and($result->untouched)->toBe(1)
        ->and(($this->convertedFrom)($this->preorders[2]))->toBeNull();
});

test('D88 the oldest pre-order goes first whatever its number', function (): void {
    $this->preorders[2]->forceFill(['placed_at' => now()->subYear()])->save();
    ($this->activate)(10);

    ($this->convert)();

    expect(($this->convertedFrom)($this->preorders[2]))->not->toBeNull()
        ->and(($this->convertedFrom)($this->preorders[0]))->toBeNull();
});

test('BR-PR04 the converted orders are priced at today\'s tiers for the converted quantity, not the pre-order price', function (): void {
    Sku::query()->whereKey($this->sku->id)->update(['base_price' => '95.00']);
    SkuPriceTier::query()->where('sku_id', $this->sku->id)->update(['unit_price' => '88.00']);
    ($this->activate)(15);

    ($this->convert)();

    expect(($this->line)(($this->convertedFrom)($this->preorders[0]) ?? throw new RuntimeException)->only(['quantity', 'unit_price', 'line_total']))->toBe(['quantity' => 10, 'unit_price' => '88.00', 'line_total' => '880.00'])
        ->and(($this->line)(($this->convertedFrom)($this->preorders[1]) ?? throw new RuntimeException)->only(['quantity', 'unit_price', 'line_total']))->toBe(['quantity' => 5, 'unit_price' => '95.00', 'line_total' => '475.00']);
});

test('BR-INV03 the converted orders reserve their stock; the pre-orders still hold none', function (): void {
    ($this->activate)(15);

    ($this->convert)();

    expect(SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 300, 'reserved' => 300])
        ->and(OrderItem::query()->whereIn('order_id', array_map(fn (Order $order): int => $order->id, $this->preorders))->pluck('reserved')->unique()->all())->toBe([false]);
});

test('D88 a converted order is a normal pending order of the customer, placed by the merchant, delivered where the pre-order was', function (): void {
    $this->preorders[0]->forceFill(['delivery_name' => 'فرع الدقي', 'customer_note' => 'قبل المدارس'])->save();
    ($this->activate)(10);

    ($this->convert)();
    $order = ($this->convertedFrom)($this->preorders[0]);

    expect($order?->only(['kind', 'status', 'customer_id', 'placed_by_type', 'placed_by_id', 'delivery_name', 'delivery_address', 'customer_note']))->toEqual([
        'kind' => OrderKind::Order, 'status' => OrderStatus::Pending, 'customer_id' => $this->first->id, 'placed_by_type' => ActorType::TenantUser,
        'placed_by_id' => $this->owner->id, 'delivery_name' => 'فرع الدقي', 'delivery_address' => $this->preorders[0]->delivery_address, 'customer_note' => 'قبل المدارس',
    ])
        ->and(OrderStatusHistory::query()->where('order_id', $order?->id)->sole()->only(['to_status', 'actor_type', 'actor_id']))
        ->toBe(['to_status' => OrderStatus::Pending, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id]);
});

test('D88 the pre-order keeps what was not converted: its line, totals and a converted change; a full one is closed', function (): void {
    ($this->activate)(15);

    ($this->convert)();

    expect($this->preorders[0]->refresh()->preorderState())->toBe(PreorderState::Converted)
        ->and($this->preorders[0]->only(['status', 'items_total', 'order_total']))->toBe(['status' => OrderStatus::Pending, 'items_total' => '0.00', 'order_total' => '0.00'])
        ->and($this->preorders[1]->refresh()->preorderState())->toBe(PreorderState::Open)
        ->and(($this->line)($this->preorders[1])->only(['quantity', 'converted_quantity', 'line_total']))->toBe(['quantity' => 10, 'converted_quantity' => 5, 'line_total' => '425.00'])
        ->and($this->preorders[1]->order_total)->toBe('425.00')
        ->and(OrderItemChange::query()->where('order_id', $this->preorders[1]->id)->sole()->only(['change', 'old_quantity', 'new_quantity', 'unit_price_before', 'unit_price_after', 'actor_id']))->toBe([
            'change' => OrderItemChangeType::Converted, 'old_quantity' => 10, 'new_quantity' => 5, 'unit_price_before' => '85.00', 'unit_price_after' => '90.00', 'actor_id' => $this->owner->id,
        ]);
});

test('D88 converting again once more stock arrives finishes the partial pre-order first', function (): void {
    ($this->activate)(15);
    ($this->convert)();
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 300 + 100]);

    $result = ($this->convert)();

    expect(array_column($result->orders, 'preorder_number'))->toBe([$this->preorders[1]->number])
        ->and(array_column($result->orders, 'quantity'))->toBe([5])
        ->and($this->preorders[1]->refresh()->preorderState())->toBe(PreorderState::Converted)
        ->and($result->untouched)->toBe(1)
        ->and(Order::query()->where('converted_from_id', $this->preorders[1]->id)->count())->toBe(2);
});

test('D88 a pre-order of a customer who can no longer order, or a cancelled one, is left alone', function (): void {
    $this->first->forceFill(['status' => CustomerStatus::Blocked])->save();
    $this->preorders[1]->forceFill(['status' => OrderStatus::Cancelled])->save();
    ($this->activate)(30);

    $result = ($this->convert)();

    expect(array_column($result->orders, 'preorder_number'))->toBe([$this->preorders[2]->number])
        ->and($result->untouched)->toBe(1)
        ->and($this->preorders[0]->refresh()->preorderState())->toBe(PreorderState::Open);
});

test('D88 only the lines of the converted product move; the pre-order stays open for the others', function (): void {
    $other = $this->preorderableSku(name: 'أطلس ٢٠٢٧');
    $mixed = $this->placePreorder($this->makeCustomer(), [$this->sku->id => 5, $other->id => 5]);
    $mixed->forceFill(['placed_at' => now()->subYear()])->save();
    ($this->activate)(5);

    ($this->convert)();
    $order = ($this->convertedFrom)($mixed);

    expect(OrderItem::query()->where('order_id', $order?->id)->pluck('sku_id')->all())->toBe([$this->sku->id])
        ->and($mixed->refresh()->preorderState())->toBe(PreorderState::Open)
        ->and($mixed->order_total)->toBe('450.00');
});

test('BR-N03 each customer is told their order exists; the merchant gets no bell for it', function (): void {
    ($this->activate)(15);

    ($this->convert)();

    expect(OutboundMessage::query()->where('purpose', MessagePurpose::OrderStatus)->orderBy('id')->pluck('order_id')->all())
        ->toBe([($this->convertedFrom)($this->preorders[0])?->id, ($this->convertedFrom)($this->preorders[1])?->id])
        ->and(MerchantNotification::query()->count())->toBe(0);
});

test('BR-L BR-O04 each conversion is in the pre-order\'s history and audited on it', function (): void {
    ($this->activate)(10);

    ($this->convert)();
    $order = ($this->convertedFrom)($this->preorders[0]);

    expect(OrderStatusHistory::query()->where('order_id', $this->preorders[0]->id)->latest('id')->firstOrFail()->only(['from_status', 'to_status', 'actor_type', 'note']))
        ->toBe(['from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Pending, 'actor_type' => ActorType::TenantUser, 'note' => "تحويل إلى طلب #{$order?->number}"])
        ->and(AuditLog::query()->where('action', 'order.preorder_converted')->sole()->new_values)->toEqual([
            'order_number' => $order?->number, 'lines' => [['sku_id' => $this->sku->id, 'quantity' => 10, 'remaining' => 0]], 'order_total' => '0.00', 'converted' => true,
        ])
        ->and(AuditLog::query()->where('action', 'order.created')->sole()->new_values)->toMatchArray(['converted_from' => $this->preorders[0]->number]);
});

test('D88 with no stock nothing is converted and every pre-order is untouched', function (): void {
    ($this->activate)(0);

    $result = ($this->convert)();

    expect($result->orders)->toBe([])
        ->and($result->untouched)->toBe(3)
        ->and(Order::query()->where('kind', OrderKind::Order)->count())->toBe(0);
});
