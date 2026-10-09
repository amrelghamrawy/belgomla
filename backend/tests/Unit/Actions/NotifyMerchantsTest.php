<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\NotifyMerchants;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-N02 a new order notifies every owner with the number, customer and total', function (): void {
    $order = Order::factory()->create(['number' => 1024, 'delivery_name' => 'مكتبة الأمل', 'order_total' => '1020.00']);

    $notification = app(NotifyMerchants::class)->handle(NotificationType::NewOrder, $order);

    expect($notification->refresh()->only(['tenant_id', 'tenant_user_id', 'type', 'read_at']))->toBe([
        'tenant_id' => $this->tenant->id, 'tenant_user_id' => null, 'type' => NotificationType::NewOrder, 'read_at' => null,
    ])
        ->and($notification->data)->toEqual(['order_number' => 1024, 'customer_name' => 'مكتبة الأمل', 'order_total' => '1020.00']);
});

test('BR-N02 a customer cancellation carries the reason', function (): void {
    $order = Order::factory()->cancelled(CancelledBy::Customer, 'طلبت بالخطأ')->create(['number' => 7, 'delivery_name' => 'مكتبة الأمل', 'order_total' => '90.00']);

    $notification = app(NotifyMerchants::class)->handle(NotificationType::CustomerCancelled, $order);

    expect($notification->refresh()->data)->toEqual(['order_number' => 7, 'customer_name' => 'مكتبة الأمل', 'order_total' => '90.00', 'reason' => 'طلبت بالخطأ'])
        ->and(MerchantNotification::query()->count())->toBe(1);
});
