<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CustomerRecentOrders;
use App\Modules\Orders\Services\NullCollectsPayments;
use App\Modules\Orders\Services\OrdersHoldingSku;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
});

test('Orders fills the contracts of Inventory and Customers, and Collections has none yet', function (): void {
    expect(app(ReservationHolders::class))->toBeInstanceOf(OrdersHoldingSku::class)
        ->and(app(RecentOrders::class))->toBeInstanceOf(CustomerRecentOrders::class)
        ->and(app(CollectsPayments::class))->toBeInstanceOf(NullCollectsPayments::class);
});

test('BR-INV08 the orders holding a sku are its open orders, oldest first, with the base units each holds', function (): void {
    $first = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    $second = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5]);
    app(TenantContext::class)->set($this->tenant);
    $second->forceFill(['status' => OrderStatus::Confirmed])->save();

    expect(app(OrdersHoldingSku::class)->ordersHolding($this->sku))->toBe([
        ['number' => $first->number, 'status' => 'pending', 'quantity_base' => 120],
        ['number' => $second->number, 'status' => 'confirmed', 'quantity_base' => 100],
    ]);
});

test('BR-INV08 delivered and cancelled orders hold nothing, nor do orders of other skus', function (OrderStatus $status): void {
    $order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    $this->placeOrder($this->customer, [$this->orderableSku(name: 'كراسة')->id => 5]);
    app(TenantContext::class)->set($this->tenant);
    $order->forceFill(['status' => $status])->save();

    expect(app(OrdersHoldingSku::class)->ordersHolding($this->sku))->toBe([]);
})->with(['delivered' => [OrderStatus::Delivered], 'cancelled' => [OrderStatus::Cancelled]]);

test('the recent orders of a customer are the latest five, newest first, in the contract shape', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $orders = collect(range(1, 6))->map(fn (int $day): Order => Order::factory()->for($this->customer)->create(['order_total' => '100.00', 'placed_at' => now()->subDays(7 - $day)]));
    Order::factory()->create();

    $recent = app(CustomerRecentOrders::class)->forCustomer($this->customer->id);

    expect(array_column($recent, 'id'))->toBe($orders->reverse()->take(5)->pluck('id')->values()->all())
        ->and($recent[0])->toBe([
            'id' => $orders->last()->id,
            'number' => $orders->last()->number,
            'status' => 'pending',
            'total' => '100.00',
            'placed_at' => $orders->last()->placed_at->toIso8601String(),
        ]);
});

test('the recent orders honour the limit', function (): void {
    app(TenantContext::class)->set($this->tenant);
    Order::factory()->for($this->customer)->count(3)->create();

    expect(app(CustomerRecentOrders::class)->forCustomer($this->customer->id, 2))->toHaveCount(2);
});

test('until collections exist the full-amount shortcut records nothing', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $order = Order::factory()->create();

    new NullCollectsPayments()->collectRemaining($order, null);

    expect($order->refresh()->collected_amount)->toBe('0.00');
});
