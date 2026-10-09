<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Actions\RecordStatusChange;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-O04 it appends the order\'s current status with the previous one, actor and note', function (): void {
    $order = Order::factory()->confirmed()->create();

    $entry = app(RecordStatusChange::class)->handle($order, OrderStatus::Pending, ActorType::TenantUser, 4, 'تم');

    expect($entry->refresh()->only(['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
        'order_id' => $order->id, 'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Confirmed, 'actor_type' => ActorType::TenantUser, 'actor_id' => 4, 'note' => 'تم',
    ]);
});
