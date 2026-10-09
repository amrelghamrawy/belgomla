<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\Scopes\Open;
use App\Modules\Orders\Models\Scopes\Stale;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $order = Order::factory()->create()->fresh();

    expect(array_keys($order?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'number', 'customer_id', 'status', 'items_total', 'order_total', 'collected_amount', 'collection_status',
        'delivery_name', 'delivery_phone', 'delivery_address', 'customer_note', 'cancel_reason', 'cancelled_by_type',
        'placed_at', 'confirmed_at', 'delivered_at', 'cancelled_at', 'created_at', 'updated_at',
    ]);
});

test('casts: enums, money strings, the address array and immutable timestamps', function (): void {
    $order = Order::factory()->cancelled(CancelledBy::Customer)->create(['items_total' => 1020, 'order_total' => '1020.5'])->fresh();

    expect($order?->status)->toBe(OrderStatus::Cancelled)
        ->and($order?->collection_status)->toBe(CollectionStatus::Unpaid)
        ->and($order?->cancelled_by_type)->toBe(CancelledBy::Customer)
        ->and($order?->items_total)->toBe('1020.00')
        ->and($order?->order_total)->toBe('1020.50')
        ->and($order?->collected_amount)->toBe('0.00')
        ->and($order?->delivery_address)->toBeArray()->toHaveKey('city', 'مدينة نصر')
        ->and($order?->placed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($order?->cancelled_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a new order is unpaid with nothing collected', function (): void {
    $order = new Order;

    expect($order->collected_amount)->toBe('0.00')
        ->and($order->collection_status)->toBe(CollectionStatus::Unpaid);
});

test('BR-O09 routes address an order by its number', function (): void {
    expect(new Order()->getRouteKeyName())->toBe('number');
});

test('BR-O09 a number is unique within a tenant only', function (): void {
    Order::factory()->create(['number' => 7]);
    $this->inTenant(fn () => Order::factory()->create(['number' => 7]), $this->otherTenant());

    expect(fn () => Order::factory()->create(['number' => 7]))->toThrow(QueryException::class);
});

test('the database refuses an unknown status', function (): void {
    expect(fn () => Order::factory()->create()->newQuery()->update(['status' => 'lost']))->toThrow(QueryException::class);
});

test('an order belongs to its customer and has its lines and history oldest first', function (): void {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->withItems([[], []])->create();
    OrderStatusHistory::factory()->for($order)->count(2)->create();

    expect($order->customer->is($customer))->toBeTrue()
        ->and($order->items)->toHaveCount(2)->each->toBeInstanceOf(OrderItem::class)
        ->and($order->items->pluck('id')->all())->toBe(OrderItem::query()->orderBy('id')->pluck('id')->all())
        ->and($order->history)->toHaveCount(2);
});

test('BR-INV06 a sale movement must point at an existing order', function (): void {
    expect(fn () => InventoryMovement::factory()->sale(1, 0, orderId: 999_999)->create())->toThrow(QueryException::class);
});

test('BR-INV07 the stale scope keeps pending orders placed before the threshold', function (): void {
    $stale = Order::factory()->stale(25)->create();
    Order::factory()->stale(23)->create();
    Order::factory()->confirmed()->create(['placed_at' => now()->subDays(3)]);

    expect(Order::query()->tap(new Stale(24))->pluck('id')->all())->toBe([$stale->id]);
});

test('the open scope leaves out delivered and cancelled orders', function (): void {
    $open = [Order::factory()->create()->id, Order::factory()->outForDelivery()->create()->id];
    Order::factory()->delivered()->create();
    Order::factory()->cancelled()->create();

    expect(Order::query()->tap(new Open)->orderBy('id')->pluck('id')->all())->toBe($open);
});
