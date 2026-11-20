<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $change = OrderItemChange::factory()->create()->fresh();

    expect(array_keys($change?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'order_id', 'order_item_id', 'change', 'old_quantity', 'new_quantity', 'unit_price_before', 'unit_price_after', 'actor_id', 'created_at',
    ]);
});

test('casts: the change kind, integers, money strings and an immutable time', function (): void {
    $change = OrderItemChange::factory()->create(['old_quantity' => '5', 'new_quantity' => '8', 'unit_price_before' => 90, 'unit_price_after' => '85.5'])->fresh();

    expect($change?->only(['change', 'old_quantity', 'new_quantity', 'unit_price_before', 'unit_price_after']))->toBe([
        'change' => OrderItemChangeType::Quantity, 'old_quantity' => 5, 'new_quantity' => 8, 'unit_price_before' => '90.00', 'unit_price_after' => '85.50',
    ])->and($change?->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a change belongs to its order and its line', function (): void {
    $item = OrderItem::factory()->create();

    $change = OrderItemChange::factory()->create(['order_item_id' => $item->id]);

    expect($change->orderItem?->is($item))->toBeTrue()
        ->and($change->order->is($item->order))->toBeTrue();
});

test('the factory states are the four kinds of change', function (): void {
    expect([
        OrderItemChange::factory()->added()->create()->only(['change', 'old_quantity']),
        OrderItemChange::factory()->removed()->create()->only(['change', 'new_quantity']),
        OrderItemChange::factory()->deliveredShort()->create()->only(['change', 'new_quantity']),
    ])->toBe([
        ['change' => OrderItemChangeType::Added, 'old_quantity' => null],
        ['change' => OrderItemChangeType::Removed, 'new_quantity' => null],
        ['change' => OrderItemChangeType::DeliveredShort, 'new_quantity' => 6],
    ]);
});

test('the database refuses an unknown change', function (): void {
    expect(fn () => OrderItemChange::factory()->create()->newQuery()->update(['change' => 'renamed']))->toThrow(QueryException::class);
});
