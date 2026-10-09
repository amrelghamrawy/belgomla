<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $entry = OrderStatusHistory::factory()->create()->fresh();

    expect(array_keys($entry?->toArray() ?? []))->toBe(['id', 'tenant_id', 'order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note', 'created_at']);
});

test('casts: status and actor enums, an immutable created_at and no updated_at', function (): void {
    $entry = OrderStatusHistory::factory()->create(['from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Confirmed, 'actor_type' => ActorType::TenantUser, 'actor_id' => '3'])->fresh();

    expect($entry?->from_status)->toBe(OrderStatus::Pending)
        ->and($entry?->to_status)->toBe(OrderStatus::Confirmed)
        ->and($entry?->actor_type)->toBe(ActorType::TenantUser)
        ->and($entry?->actor_id)->toBe(3)
        ->and($entry?->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(OrderStatusHistory::UPDATED_AT)->toBeNull()
        ->and(new OrderStatusHistory()->getTable())->toBe('order_status_history');
});

test('an entry belongs to its order', function (): void {
    $order = Order::factory()->create();

    expect(OrderStatusHistory::factory()->for($order)->create()->order->is($order))->toBeTrue();
});
