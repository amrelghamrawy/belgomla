<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Http\Resources\DashboardOrderResource;
use App\Modules\Orders\Http\Resources\NotificationResource;
use App\Modules\Orders\Http\Resources\OrderItemResource;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\PlacedOrderResource;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
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
        'status' => 'pending',
        'status_label' => 'قيد الانتظار',
        'placed_at' => now()->toIso8601String(),
        'items_total' => '170.00',
        'order_total' => '170.00',
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
            'sku_id' => $item->sku_id, 'product_name' => $item->product_name, 'sku_code' => $item->sku_code, 'sku_name' => $item->sku_name,
            'unit' => $item->unit_name, 'conversion_factor' => 1, 'quantity' => 2, 'cover_price' => null, 'unit_price' => '85.00',
            'discount_percent' => null, 'tier_min_quantity' => null, 'line_total' => '170.00',
        ]],
        'history' => [['from' => null, 'to' => 'pending', 'at' => now()->toIso8601String(), 'by' => 'customer']],
    ]);
});

test('a cancelled order shows the reason and who cancelled', function (): void {
    $order = Order::factory()->cancelled(CancelledBy::Customer, 'طلبت بالخطأ')->create();

    expect(rendered(new OrderResource($order), storeRequest()))->toMatchArray(['status' => 'cancelled', 'cancel_reason' => 'طلبت بالخطأ', 'cancelled_by' => 'customer']);
});

test('the order item resource is the line snapshot', function (): void {
    $item = OrderItem::query()->firstOrFail();

    expect(array_keys(rendered(new OrderItemResource($item), storeRequest())))->toBe([
        'sku_id', 'product_name', 'sku_code', 'sku_name', 'unit', 'conversion_factor', 'quantity', 'cover_price', 'unit_price',
        'discount_percent', 'tier_min_quantity', 'line_total',
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

    expect(rendered(new DashboardOrderResource($order, now()->subDay()), dashboardRequest('/api/dashboard/orders')))->toBe([
        'id' => $order->id,
        ...($this->expectedBase)($order),
        'customer' => ['id' => $this->customer->id, 'name' => 'مكتبة الأمل', 'phone' => '+201012345678'],
        'confirmed_at' => null,
        'delivered_at' => null,
        'cancelled_at' => null,
        'is_stale' => false,
    ]);
});

test('the dashboard detail adds lines, history with actor and note, and the collections', function (): void {
    $order = Order::query()->with(['items', 'history', 'customer'])->findOrFail($this->order->id);

    $data = rendered(new DashboardOrderResource($order, now()->subDay()), dashboardRequest('/api/dashboard/orders/1024'));

    expect($data['history'])->toBe([['from' => null, 'to' => 'pending', 'at' => now()->toIso8601String(), 'by' => ActorType::Customer->value, 'actor_id' => $this->customer->id, 'note' => null]])
        ->and($data['items'])->toHaveCount(1)
        ->and($data['collections'])->toBe([]);
});

test('BR-INV07 a pending order placed before the threshold is stale, others are not', function (): void {
    $order = Order::factory()->stale(25)->create();
    $confirmed = Order::factory()->confirmed()->create(['placed_at' => now()->subDays(3)]);

    expect(rendered(new DashboardOrderResource($order, now()->subHours(24)), dashboardRequest())['is_stale'])->toBeTrue()
        ->and(rendered(new DashboardOrderResource($confirmed, now()->subHours(24)), dashboardRequest())['is_stale'])->toBeFalse();
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
