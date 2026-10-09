<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CannotCancel;
use App\Modules\Orders\Exceptions\OrderHasCollections;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    AuditLog::query()->delete();
    MerchantNotification::query()->delete();

    $this->cancel = fn (CancelledBy $by, string $reason = 'تغيير الطلب'): Order => app(CancelOrder::class)
        ->handle($this->order, new CancelData($reason), $by, $by === CancelledBy::Customer ? $this->customer->id : $this->owner->id);
    $this->stock = fn (): array => SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'reserved']);
    $this->at = fn (OrderStatus $status) => $this->order->forceFill(['status' => $status])->save();
});

test('BR-O02 the customer cancels a pending order with a reason', function (): void {
    $this->freezeSecond();

    $order = ($this->cancel)(CancelledBy::Customer, 'طلبت بالخطأ');

    expect($order->refresh()->only(['status', 'cancel_reason', 'cancelled_by_type']))->toBe([
        'status' => OrderStatus::Cancelled, 'cancel_reason' => 'طلبت بالخطأ', 'cancelled_by_type' => CancelledBy::Customer,
    ])
        ->and($order->cancelled_at?->equalTo(now()))->toBeTrue();
});

test('BR-INV04 cancelling releases the reservation without a movement', function (CancelledBy $by): void {
    ($this->cancel)($by);

    expect(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 0])
        ->and(InventoryMovement::query()->count())->toBe(0);
})->with(['by the customer' => [CancelledBy::Customer], 'by the merchant' => [CancelledBy::TenantUser]]);

test('BR-O04 the cancellation is in the history with its actor and the reason as note', function (CancelledBy $by, ActorType $actor): void {
    ($this->cancel)($by, 'نفاد الكمية');

    expect(OrderStatusHistory::query()->latest('id')->first()?->only(['from_status', 'to_status', 'actor_type', 'note']))->toBe([
        'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Cancelled, 'actor_type' => $actor, 'note' => 'نفاد الكمية',
    ]);
})->with(['customer' => [CancelledBy::Customer, ActorType::Customer], 'merchant' => [CancelledBy::TenantUser, ActorType::TenantUser]]);

test('BR-L a cancellation is audited as order.cancelled', function (): void {
    ($this->cancel)(CancelledBy::TenantUser, 'نفاد الكمية');

    expect(AuditLog::query()->sole()->only(['action', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'order.cancelled', 'entity_id' => $this->order->id,
        'old_values' => ['status' => 'pending'],
        'new_values' => ['status' => 'cancelled', 'cancel_reason' => 'نفاد الكمية', 'cancelled_by' => 'tenant_user'],
    ]);
});

test('BR-N02 a customer cancellation notifies the merchant with the reason', function (): void {
    ($this->cancel)(CancelledBy::Customer, 'طلبت بالخطأ');

    expect(MerchantNotification::query()->sole())
        ->type->toBe(NotificationType::CustomerCancelled)
        ->data->toEqual(['order_number' => $this->order->number, 'customer_name' => $this->order->delivery_name, 'order_total' => '540.00', 'reason' => 'طلبت بالخطأ']);
});

test('BR-N02 a merchant cancellation notifies nobody', function (): void {
    ($this->cancel)(CancelledBy::TenantUser);

    expect(MerchantNotification::query()->count())->toBe(0);
});

test('BR-O02 the customer cannot cancel once the order is confirmed', function (OrderStatus $status): void {
    ($this->at)($status);

    expect(fn () => ($this->cancel)(CancelledBy::Customer))->toThrow(function (CannotCancel $e) use ($status): void {
        expect($e->status)->toBe(409)
            ->and($e->errorCode)->toBe('invalid_transition')
            ->and($e->details)->toBe(['from' => $status->value, 'to' => 'cancelled']);
    });

    expect($this->order->refresh()->status)->toBe($status)
        ->and(($this->stock)()['reserved'])->toBe(120);
})->with(['confirmed' => [OrderStatus::Confirmed], 'preparing' => [OrderStatus::Preparing], 'out for delivery' => [OrderStatus::OutForDelivery]]);

test('BR-O02 the merchant cancels any order before delivery', function (OrderStatus $status): void {
    ($this->at)($status);

    expect(($this->cancel)(CancelledBy::TenantUser)->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(($this->stock)()['reserved'])->toBe(0);
})->with(['confirmed' => [OrderStatus::Confirmed], 'preparing' => [OrderStatus::Preparing], 'out for delivery' => [OrderStatus::OutForDelivery]]);

test('BR-O01 nobody cancels a delivered or cancelled order', function (OrderStatus $status, CancelledBy $by): void {
    ($this->at)($status);

    expect(fn () => ($this->cancel)($by))->toThrow(CannotCancel::class);

    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'delivered, merchant' => [OrderStatus::Delivered, CancelledBy::TenantUser],
    'cancelled, merchant' => [OrderStatus::Cancelled, CancelledBy::TenantUser],
    'delivered, customer' => [OrderStatus::Delivered, CancelledBy::Customer],
]);

test('BR-O03 D7 an order with collected money cannot be cancelled', function (CancelledBy $by): void {
    $this->order->forceFill(['collected_amount' => '100.00'])->save();

    expect(fn () => ($this->cancel)($by))->toThrow(function (OrderHasCollections $e): void {
        expect($e->status)->toBe(409)
            ->and($e->errorCode)->toBe('has_collections')
            ->and($e->details)->toBe(['collected_amount' => '100.00']);
    });

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(($this->stock)()['reserved'])->toBe(120)
        ->and(MerchantNotification::query()->count())->toBe(0);
})->with(['customer' => [CancelledBy::Customer], 'merchant' => [CancelledBy::TenantUser]]);
