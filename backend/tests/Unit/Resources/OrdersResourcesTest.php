<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Data\PendingClockData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Http\Resources\NotificationResource;
use App\Modules\Orders\Http\Resources\OrderItemResource;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\OrderReturnResource;
use App\Modules\Orders\Http\Resources\PlacedOrderResource;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->freezeSecond();
    $this->customer = Customer::factory()->create(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->order = Order::factory()->for($this->customer)->withItems([['quantity' => 2, 'unit_price' => '85.00']])->create([
        'number' => 1024, 'delivery_name' => 'مكتبة الأمل', 'delivery_phone' => '+201012345678', 'customer_note' => 'صباحًا',
    ]);
    OrderStatusHistory::factory()->for($this->order)->create(['actor_id' => $this->customer->id]);
    $this->expectedBase = fn (Order $order): array => [
        'number' => 1024,
        'kind' => 'order',
        'status' => 'pending',
        'status_label' => 'قيد الانتظار',
        'placed_at' => now()->toIso8601String(),
        'preorder_state' => null,
        'converted_at' => null,
        'items_total' => '170.00',
        'discount' => null,
        'order_total' => '170.00',
        'returned_total' => '0.00',
        'net_total' => '170.00',
        'collection_status' => 'unpaid',
        'collected_amount' => '0.00',
        'delivery' => ['name' => 'مكتبة الأمل', 'phone' => '+201012345678', 'address' => $order->delivery_address],
        'customer_note' => 'صباحًا',
        'cancel_reason' => null,
        'cancelled_by' => null,
    ];
});

test('the store order list entry has no lines or history', function (): void {
    $order = Order::query()->findOrFail($this->order->id);

    expect(rendered(new OrderResource($order), storeRequest('/api/store/orders')))->toBe(($this->expectedBase)($order));
});

test('the store order detail adds the lines and the history', function (): void {
    $order = Order::query()->with(['items', 'history'])->findOrFail($this->order->id);
    $item = $order->items->sole();

    expect(rendered(new OrderResource($order), storeRequest('/api/store/orders/1024')))->toBe([
        ...($this->expectedBase)($order),
        'items' => [[
            'id' => $item->id, 'sku_id' => $item->sku_id, 'product_name' => $item->product_name, 'sku_code' => $item->sku_code, 'sku_name' => $item->sku_name,
            'unit' => $item->unit_name, 'conversion_factor' => 1, 'quantity' => 2, 'cover_price' => null, 'list_unit_price' => '85.00',
            'manual_discount' => null, 'unit_price' => '85.00', 'discount_percent' => null, 'tier_min_quantity' => null, 'price_source' => 'base', 'line_total' => '170.00',
            'delivered_quantity' => null, 'removed_at' => null, 'converted_quantity' => 0,
        ]],
        'history' => [['from' => null, 'to' => 'pending', 'at' => now()->toIso8601String(), 'by' => 'customer']],
    ]);
});

test('D85 a discounted line shows its list price, the discount and the price charged', function (): void {
    $item = OrderItem::query()->firstOrFail();
    $item->forceFill(['manual_discount_type' => DiscountType::Percent, 'manual_discount_value' => '10', 'unit_price' => '76.50', 'line_total' => '153.00'])->save();

    expect(rendered(new OrderItemResource($item), storeRequest()))->toMatchArray([
        'list_unit_price' => '85.00', 'manual_discount' => ['type' => 'percent', 'value' => '10.00'], 'unit_price' => '76.50', 'line_total' => '153.00',
    ]);
});

test('D85 the store shows the order discount without the merchant\'s reason, the dashboard with it', function (): void {
    $this->order->forceFill(['discount_type' => DiscountType::Amount, 'discount_value' => '20', 'discount_amount' => '20', 'discount_reason' => 'عميل دائم', 'order_total' => '150.00'])->save();
    $order = Order::query()->findOrFail($this->order->id);

    expect(rendered(new OrderResource($order), storeRequest('/api/store/orders'))['discount'])->toBe(['type' => 'amount', 'value' => '20.00', 'amount' => '20.00'])
        ->and(rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/orders'))['discount'])
        ->toBe(['type' => 'amount', 'value' => '20.00', 'amount' => '20.00', 'reason' => 'عميل دائم']);
});

test('a cancelled order shows the reason and who cancelled', function (): void {
    $order = Order::factory()->cancelled(CancelledBy::Customer, 'طلبت بالخطأ')->create();

    expect(rendered(new OrderResource($order), storeRequest()))->toMatchArray(['status' => 'cancelled', 'cancel_reason' => 'طلبت بالخطأ', 'cancelled_by' => 'customer']);
});

test('the order item resource is the line snapshot', function (): void {
    $item = OrderItem::query()->firstOrFail();

    expect(array_keys(rendered(new OrderItemResource($item), storeRequest())))->toBe([
        'id', 'sku_id', 'product_name', 'sku_code', 'sku_name', 'unit', 'conversion_factor', 'quantity', 'cover_price', 'list_unit_price',
        'manual_discount', 'unit_price', 'discount_percent', 'tier_min_quantity', 'price_source', 'line_total', 'delivered_quantity', 'removed_at',
        'converted_quantity',
    ]);
});

test('the checkout answer wraps the order detail in order', function (): void {
    $order = Order::query()->with(['items', 'history'])->findOrFail($this->order->id);

    expect(rendered(new PlacedOrderResource($order), storeRequest('/api/store/checkout')))->toBe([
        'order' => rendered(new OrderResource($order), storeRequest('/api/store/checkout')),
    ]);
});

test('the dashboard list entry adds the id, customer, timestamps and staleness', function (): void {
    $order = Order::query()->with('customer')->findOrFail($this->order->id);

    expect(rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/orders')))->toBe([
        'id' => $order->id,
        ...($this->expectedBase)($order),
        'customer' => ['id' => $this->customer->id, 'name' => 'مكتبة الأمل', 'phone' => '+201012345678'],
        'placed_by' => 'customer',
        'placed_by_id' => null,
        'confirmed_at' => null,
        'delivered_at' => null,
        'cancelled_at' => null,
        'is_stale' => false,
        'expires_at' => null,
    ]);
});

test('the dashboard detail adds lines, history with actor and note, and the collections', function (): void {
    $order = Order::query()->with(['items', 'history', 'customer'])->findOrFail($this->order->id);

    $data = rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/orders/1024'));

    expect($data['history'])->toBe([['from' => null, 'to' => 'pending', 'at' => now()->toIso8601String(), 'by' => ActorType::Customer->value, 'actor_id' => $this->customer->id, 'note' => null]])
        ->and($data['items'])->toHaveCount(1)
        ->and($data['collections'])->toBe([]);
});

test('BR-INV07 a pending order placed before the threshold is stale, others are not', function (): void {
    $order = Order::factory()->stale(25)->create();
    $confirmed = Order::factory()->confirmed()->create(['placed_at' => now()->subDays(3)]);

    $clock = new PendingClockData(now()->subHours(24), null);

    expect(rendered(new DashboardOrderResource($order, $clock), dashboardRequest())['is_stale'])->toBeTrue()
        ->and(rendered(new DashboardOrderResource($confirmed, $clock), dashboardRequest())['is_stale'])->toBeFalse();
});

test('D81 a pending order expires its expiry hours after it was placed; a confirmed one never', function (): void {
    $order = Order::factory()->stale(25)->create();
    $confirmed = Order::factory()->confirmed()->create(['placed_at' => now()->subDays(3)]);
    $clock = new PendingClockData(now()->subHours(24), 48);

    expect(rendered(new DashboardOrderResource($order, $clock), dashboardRequest())['expires_at'])->toBe($order->placed_at->addHours(48)->toIso8601String())
        ->and(rendered(new DashboardOrderResource($confirmed, $clock), dashboardRequest())['expires_at'])->toBeNull();
});

test('BR-R05 the store order detail shows live returns without the merchant\'s reason', function (): void {
    $order = Order::query()->findOrFail($this->order->id);
    $return = OrderReturn::factory()->for($order)->create(['total' => '85.00', 'returned_at' => now()]);
    $item = OrderReturnItem::factory()->for($return)->create(['order_item_id' => $order->items()->sole()->id, 'quantity' => 1]);
    $order->load(['items', 'history', 'returns.items.orderItem']);

    expect(rendered(new OrderResource($order), storeRequest('/api/store/orders/1024'))['returns'])->toBe([[
        'number' => 1,
        'total' => '85.00',
        'refund_amount' => '0.00',
        'refund_method' => null,
        'returned_at' => now()->toIso8601String(),
        'items' => [['product_name' => $item->orderItem->product_name, 'sku_code' => $item->orderItem->sku_code, 'unit' => $item->orderItem->unit_name, 'quantity' => 1, 'unit_price' => '85.00', 'line_total' => '85.00']],
    ]]);
});

test('BR-R06 a return renders its order balance, customer, lines and void details', function (): void {
    $order = Order::query()->with('customer')->findOrFail($this->order->id);
    $owner = $this->makeOwner();
    $return = OrderReturn::factory()->for($order)->voided('مكرر')->create(['total' => '85.00', 'refund_amount' => '5.00', 'refund_method' => 'cash', 'recorded_by' => $owner->id, 'voided_by' => $owner->id, 'returned_at' => now()]);
    $item = OrderReturnItem::factory()->for($return)->create(['order_item_id' => $order->items()->sole()->id, 'quantity' => 1]);
    $return = OrderReturn::query()->with('items.orderItem')->findOrFail($return->id)->setRelation('order', $order);

    expect(rendered(new OrderReturnResource($return), dashboardRequest('/api/dashboard/returns')))->toBe([
        'id' => $return->id,
        'number' => 1,
        'order' => ['id' => $order->id, 'number' => 1024, 'order_total' => '170.00', 'returned_total' => '0.00', 'net_total' => '170.00', 'collected_amount' => '0.00', 'collection_status' => 'unpaid'],
        'customer' => ['id' => $this->customer->id, 'name' => 'مكتبة الأمل'],
        'total' => '85.00',
        'refund_amount' => '5.00',
        'refund_method' => 'cash',
        'reason' => 'مرتجع نهاية الموسم',
        'returned_at' => now()->toIso8601String(),
        'recorded_by' => $owner->id,
        'is_voided' => true,
        'voided_at' => now()->toIso8601String(),
        'void_reason' => 'مكرر',
        'voided_by' => $owner->id,
        'items' => [[
            'id' => $item->id, 'order_item_id' => $item->order_item_id, 'product_name' => $item->orderItem->product_name, 'sku_code' => $item->orderItem->sku_code,
            'unit' => $item->orderItem->unit_name, 'quantity' => 1, 'quantity_base' => 1, 'unit_price' => '85.00', 'line_total' => '85.00',
        ]],
        'created_at' => now()->toIso8601String(),
    ]);
});

test('a return leaves out its order and customer when the order is not loaded', function (): void {
    $return = OrderReturn::factory()->create();

    expect(array_keys(rendered(new OrderReturnResource($return), dashboardRequest())))->not->toContain('order')->not->toContain('customer')->not->toContain('items');
});

test('BR-R06 the dashboard order detail lists every return without the order', function (): void {
    OrderReturn::factory()->for($this->order)->voided()->create();
    $order = Order::query()->with(['items', 'history', 'customer', 'returns.items.orderItem'])->findOrFail($this->order->id);

    $data = rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/orders/1024'));

    expect($data['returns'])->toHaveCount(1)
        ->and($data['returns'][0]['is_voided'])->toBeTrue()
        ->and($data['returns'][0])->not->toHaveKey('order');
});

test('BR-N02 the notification resource', function (): void {
    $notification = MerchantNotification::factory()->create(['data' => ['order_number' => 1024]])->refresh();

    expect(rendered(new NotificationResource($notification), dashboardRequest('/api/dashboard/notifications')))->toBe([
        'id' => $notification->id,
        'type' => 'new_order',
        'type_label' => 'طلب جديد',
        'details' => ['order_number' => 1024],
        'read_at' => null,
        'created_at' => now()->toIso8601String(),
    ]);
});

test('BR-O11 an order placed from the dashboard names the user who placed it', function (): void {
    $order = Order::factory()->placedByMerchant(42)->create();

    expect(rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest()))->toMatchArray(['placed_by' => 'tenant_user', 'placed_by_id' => 42]);
});

test('BR-O11 the quote resource renders every line with its tiers and problem, the total, the mov and the credit', function (): void {
    $sku = $this->orderableSku(['code' => 'Q-1'], onHand: 60, name: 'كراسة');
    app(TenantContext::class)->set($this->tenant);
    $quote = app(App\Modules\Orders\Services\OrderQuoter::class)->quote($this->customer->id, [['sku_id' => $sku->id, 'quantity' => 10]]);

    expect(rendered(new App\Modules\Orders\Http\Resources\OrderQuoteResource($quote), dashboardRequest('/api/dashboard/orders/quote')))->toBe([
        'lines' => [[
            'sku_id' => $sku->id, 'product_name' => 'كراسة', 'sku_code' => 'Q-1', 'sku_name' => 'كراسة', 'unit' => 'كرتونة', 'conversion_factor' => 20,
            'quantity' => 10, 'moq' => 5, 'available' => 3, 'cover_price' => null, 'unit_price' => '85.00', 'discount_percent' => null,
            'tier_min_quantity' => 10, 'price_source' => 'tier', 'line_total' => '850.00', 'tiers' => [['min_quantity' => 10, 'unit_price' => '85.00']],
            'problem' => ['reason' => 'insufficient_stock', 'requested' => 10, 'available' => 3],
        ]],
        'items_total' => '850.00',
        'mov' => ['enabled' => false, 'amount' => null, 'satisfied' => true],
        'credit' => null,
    ]);
});

test('BR-O12 BR-O13 a line shows what was delivered and when it was removed', function (): void {
    $item = OrderItem::query()->firstOrFail();
    $item->forceFill(['delivered_quantity' => 1, 'line_total' => '85.00'])->save();
    $removed = OrderItem::factory()->for($this->order)->removed()->create();

    expect(rendered(new OrderItemResource($item), storeRequest()))->toMatchArray(['quantity' => 2, 'delivered_quantity' => 1, 'line_total' => '85.00', 'removed_at' => null])
        ->and(rendered(new OrderItemResource($removed), storeRequest())['removed_at'])->toBe(now()->toIso8601String());
});

test('BR-O12 the order details list the line changes; the dashboard adds who made each', function (): void {
    $item = OrderItem::query()->firstOrFail();
    App\Modules\Orders\Models\OrderItemChange::factory()->create(['order_item_id' => $item->id, 'old_quantity' => 3, 'new_quantity' => 2, 'unit_price_before' => '90.00', 'unit_price_after' => '85.00', 'actor_id' => null]);
    $order = Order::query()->with(['items', 'itemChanges.orderItem', 'history'])->findOrFail($this->order->id);
    $change = [
        'order_item_id' => $item->id, 'product_name' => $item->product_name, 'sku_code' => $item->sku_code, 'change' => 'quantity',
        'old_quantity' => 3, 'new_quantity' => 2, 'unit_price_before' => '90.00', 'unit_price_after' => '85.00', 'at' => now()->toIso8601String(),
    ];

    expect(rendered(new OrderResource($order), storeRequest('/api/store/orders/1024'))['changes'])->toBe([$change])
        ->and(rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/orders/1024'))['changes'])->toBe([[...$change, 'actor_id' => null]]);
});

test('BR-O12 the lists leave the changes out', function (): void {
    $order = Order::query()->findOrFail($this->order->id);

    expect(rendered(new OrderResource($order), storeRequest('/api/store/orders')))->not->toHaveKey('changes')
        ->and(rendered(new DashboardOrderResource($order, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/orders')))->not->toHaveKey('changes');
});

test('BR-O14 a pre-order says so, with where it stands; it is never stale and never expires', function (): void {
    $preorder = Order::factory()->preorder()->create(['placed_at' => now()->subDays(3)]);
    $clock = new PendingClockData(now()->subHours(24), 48);

    $data = rendered(new DashboardOrderResource($preorder, $clock), dashboardRequest());

    expect($data)->toMatchArray(['kind' => 'preorder', 'status' => 'pending', 'preorder_state' => 'open', 'converted_at' => null, 'is_stale' => false, 'expires_at' => null]);
});

test('D88 a converted pre-order lists its orders and a converted order names its pre-order', function (): void {
    $preorder = Order::factory()->converted()->create(['number' => 7]);
    $order = Order::factory()->create(['number' => 8, 'converted_from_id' => $preorder->id, 'order_total' => '90.00']);

    $preorderData = rendered(new OrderResource($preorder->load(['convertedFrom', 'conversions'])), storeRequest());
    $orderData = rendered(new OrderResource($order->load(['convertedFrom', 'conversions'])), storeRequest());

    expect($preorderData)->toMatchArray(['preorder_state' => 'converted', 'converted_from' => null, 'conversions' => [['number' => 8, 'status' => 'pending', 'order_total' => '90.00', 'placed_at' => now()->toIso8601String()]]])
        ->and($preorderData['converted_at'])->toBe(now()->toIso8601String())
        ->and($orderData)->toMatchArray(['kind' => 'order', 'converted_from' => 7, 'conversions' => []]);
});

test('BR-O14 the pre-order list entry carries the lines but not the order page', function (): void {
    $preorder = Order::factory()->preorder()->withItems([['quantity' => 3]])->create()->load(['customer', 'items']);

    $data = rendered(new DashboardOrderResource($preorder, new PendingClockData(now()->subDay(), null)), dashboardRequest('/api/dashboard/preorders'));

    expect($data['items'])->toHaveCount(1)
        ->and($data)->not->toHaveKeys(['collections', 'messages', 'history']);
});

test('BR-O14 the pre-order demand resource: the product\'s settings, the counts and what remains under the cap', function (): void {
    $product = App\Modules\Catalog\Models\Product::factory()->preorderable(limit: 50)->create()->refresh();

    expect(rendered(new App\Modules\Orders\Http\Resources\PreorderDemandResource($product, ['quantity' => 60, 'customers' => 3, 'preorders' => 4]), dashboardRequest()))->toBe([
        'product_id' => $product->id, 'status' => 'coming_soon', 'preorder_enabled' => true, 'expected_at' => '2026-12-01',
        'limit' => 50, 'remaining' => 0, 'quantity' => 60, 'customers' => 3, 'preorders' => 4,
    ]);
});

test('D88 the conversion summary resource', function (): void {
    $summary = new App\Modules\Orders\Data\ConvertedPreorders([['number' => 12, 'preorder_number' => 7, 'quantity' => 5, 'order_total' => '450.00']], 1, 2);

    expect(rendered(new App\Modules\Orders\Http\Resources\ConvertedPreordersResource($summary), dashboardRequest()))->toBe([
        'converted_orders' => [['number' => 12, 'preorder_number' => 7, 'quantity' => 5, 'order_total' => '450.00']], 'partially' => 1, 'untouched' => 2,
    ]);
});
