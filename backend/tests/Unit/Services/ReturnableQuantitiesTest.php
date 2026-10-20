<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Orders\Services\ReturnableQuantities;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->delivered()->create();
    $this->first = OrderItem::factory()->for($this->order)->create(['quantity' => 6]);
    $this->second = OrderItem::factory()->for($this->order)->create(['quantity' => 3]);
});

test('BR-R02 every line can bring back its delivered quantity when nothing came back yet', function (): void {
    expect(app(ReturnableQuantities::class)->for($this->order))->toBe([$this->first->id => 6, $this->second->id => 3]);
});

test('BR-R02 live returns are taken off, summed over every return of the order', function (): void {
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($this->order))->create(['order_item_id' => $this->first->id, 'quantity' => 2]);
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($this->order)->state(['number' => 2]))->create(['order_item_id' => $this->first->id, 'quantity' => 1]);

    expect(app(ReturnableQuantities::class)->for($this->order))->toBe([$this->first->id => 3, $this->second->id => 3])
        ->and(app(ReturnableQuantities::class)->returned($this->order))->toBe([$this->first->id => 3]);
});

test('BR-R06 a voided return gives its quantity back', function (): void {
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($this->order)->voided())->create(['order_item_id' => $this->second->id, 'quantity' => 3]);

    expect(app(ReturnableQuantities::class)->for($this->order))->toBe([$this->first->id => 6, $this->second->id => 3]);
});

test('returns of another order never count', function (): void {
    $other = Order::factory()->delivered()->create();
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($other))->create(['order_item_id' => OrderItem::factory()->for($other)->create(['quantity' => 5])->id, 'quantity' => 5]);

    expect(app(ReturnableQuantities::class)->returned($this->order))->toBe([]);
});

test('BR-O13 a line delivered short brings back at most what was delivered', function (): void {
    $this->first->forceFill(['delivered_quantity' => 4])->save();
    OrderReturnItem::factory()->for(OrderReturn::factory()->for($this->order))->create(['order_item_id' => $this->first->id, 'quantity' => 1]);

    expect(app(ReturnableQuantities::class)->for($this->order))->toBe([$this->first->id => 3, $this->second->id => 3]);
});

test('BR-O12 a removed line has nothing to bring back', function (): void {
    $removed = OrderItem::factory()->for($this->order)->removed()->create(['quantity' => 2]);

    expect(app(ReturnableQuantities::class)->for($this->order))->not->toHaveKey($removed->id);
});
