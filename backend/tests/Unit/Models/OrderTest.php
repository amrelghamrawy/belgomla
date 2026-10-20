<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PreorderState;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\Scopes\Open;
use App\Modules\Orders\Models\Scopes\Stale;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $order = Order::factory()->create()->fresh();

    expect(array_keys($order?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'number', 'customer_id', 'status', 'items_total', 'order_total', 'collected_amount', 'collection_status',
        'delivery_name', 'delivery_phone', 'delivery_address', 'customer_note', 'cancel_reason', 'cancelled_by_type',
        'placed_at', 'confirmed_at', 'delivered_at', 'cancelled_at', 'created_at', 'updated_at', 'returned_total', 'due_at',
        'placed_by_type', 'placed_by_id', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason',
        'kind', 'converted_from_id', 'converted_at',
    ]);
});

test('casts: enums, money strings, the address array and immutable timestamps', function (): void {
    $order = Order::factory()->cancelled(CancelledBy::Customer)->create(['items_total' => 1020, 'discount_type' => 'amount', 'discount_value' => 19.5, 'discount_amount' => '19.5', 'discount_reason' => 'عميل دائم', 'order_total' => '1000.5'])->fresh();

    expect($order?->status)->toBe(OrderStatus::Cancelled)
        ->and($order?->collection_status)->toBe(CollectionStatus::Unpaid)
        ->and($order?->cancelled_by_type)->toBe(CancelledBy::Customer)
        ->and($order?->items_total)->toBe('1020.00')
        ->and($order?->discount_type)->toBe(DiscountType::Amount)
        ->and($order?->discount_value)->toBe('19.50')
        ->and($order?->discount_amount)->toBe('19.50')
        ->and($order?->order_total)->toBe('1000.50')
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

test('BR-O08 D85 the database keeps order_total = items_total − discount_amount and a discount complete', function (array $attributes): void {
    expect(fn () => Order::factory()->create(['items_total' => '100.00', 'order_total' => '100.00', ...$attributes]))->toThrow(QueryException::class);
})->with([
    'total not after the discount' => [['discount_type' => 'amount', 'discount_value' => '10', 'discount_amount' => '10.00', 'discount_reason' => 'x']],
    'a discount without a reason' => [['discount_type' => 'amount', 'discount_value' => '10', 'discount_amount' => '10.00', 'order_total' => '90.00']],
    'an amount without a discount' => [['discount_amount' => '10.00', 'order_total' => '90.00']],
    'more than the items' => [['discount_type' => 'amount', 'discount_value' => '110', 'discount_amount' => '110.00', 'discount_reason' => 'x', 'order_total' => '-10.00']],
]);

test('BR-O12 the live lines leave the removed ones out, the lines keep them', function (): void {
    $order = Order::factory()->create();
    $live = OrderItem::factory()->for($order)->create();
    $removed = OrderItem::factory()->for($order)->removed()->create();

    expect($order->liveItems->pluck('id')->all())->toBe([$live->id])
        ->and($order->items->pluck('id')->all())->toBe([$live->id, $removed->id]);
});

test('BR-O12 BR-O13 an order lists its line changes oldest first', function (): void {
    $item = OrderItem::factory()->create();
    $first = App\Modules\Orders\Models\OrderItemChange::factory()->create(['order_item_id' => $item->id]);
    $second = App\Modules\Orders\Models\OrderItemChange::factory()->removed()->create(['order_item_id' => $item->id]);

    expect($item->order->itemChanges->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('BR-O14 a new order is a normal order; the pre-order state is null for it', function (): void {
    $order = Order::factory()->create()->fresh();

    expect($order?->kind)->toBe(OrderKind::Order)
        ->and($order?->isPreorder())->toBeFalse()
        ->and($order?->preorderState())->toBeNull();
});

test('D88 a pre-order is open while something is left, converted once nothing is, cancelled when cancelled', function (): void {
    $open = Order::factory()->preorder()->create();
    $converted = Order::factory()->converted()->create();
    $cancelled = Order::factory()->preorder()->cancelled()->create();

    expect($open->fresh()?->preorderState())->toBe(PreorderState::Open)
        ->and($converted->fresh()?->preorderState())->toBe(PreorderState::Converted)
        ->and($converted->fresh()?->converted_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($cancelled->fresh()?->preorderState())->toBe(PreorderState::Cancelled);
});

test('BR-O14 relations: a converted order and its pre-order, the conversions oldest first', function (): void {
    $preorder = Order::factory()->preorder()->create();
    $first = Order::factory()->create(['converted_from_id' => $preorder->id]);
    $second = Order::factory()->create(['converted_from_id' => $preorder->id]);

    expect($first->convertedFrom?->is($preorder))->toBeTrue()
        ->and($preorder->conversions->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('BR-O14 the database refuses an unknown kind, a converted pre-order of a normal order and a pre-order converted from another', function (array $attributes, string $constraint): void {
    $preorder = Order::factory()->preorder()->create();
    $order = Order::factory()->create();

    expect(fn () => DB::table('orders')->where('id', $attributes['preorder'] ? $preorder->id : $order->id)->update([...$attributes['values'], ...($attributes['from'] ? ['converted_from_id' => $preorder->id] : [])]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'unknown kind' => [['preorder' => false, 'from' => false, 'values' => ['kind' => 'backorder']], 'orders_kind_known'],
    'converted_at on a normal order' => [['preorder' => false, 'from' => false, 'values' => ['converted_at' => now()]], 'orders_converted_at_preorder'],
    'a pre-order converted from a pre-order' => [['preorder' => true, 'from' => true, 'values' => []], 'orders_converted_from_order'],
]);

test('D88 a pre-order is never stale: it waits for its product by design', function (): void {
    Order::factory()->preorder()->create(['placed_at' => now()->subDays(30)]);

    expect(Order::query()->tap(new Stale(24))->count())->toBe(0);
});
