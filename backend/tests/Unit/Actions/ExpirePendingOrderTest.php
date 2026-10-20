<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Actions\ExpirePendingOrder;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->freezeSecond();
    $this->customer = $this->makeCustomer(['name' => 'محمود']);
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    $this->tenant->settings->update(['pending_expiry_hours' => 48]);
    MerchantNotification::query()->delete();
    OutboundMessage::query()->delete();
    AuditLog::query()->delete();

    $this->age = fn (int $hours) => $this->order->forceFill(['placed_at' => now()->subHours($hours)])->save();
    $this->expire = fn (): bool => app(ExpirePendingOrder::class)->handle($this->order);
    $this->stock = fn (): array => SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'reserved']);
});

test('BR-INV07 D81 a pending order older than the setting is cancelled by the system with the fixed reason', function (): void {
    ($this->age)(49);

    expect(($this->expire)())->toBeTrue()
        ->and($this->order->refresh()->only(['status', 'cancel_reason', 'cancelled_by_type']))->toBe([
            'status' => OrderStatus::Cancelled, 'cancel_reason' => 'انتهت مهلة تأكيد الطلب', 'cancelled_by_type' => CancelledBy::System,
        ]);
});

test('BR-INV04 the expired order releases its reservation', function (): void {
    ($this->age)(49);

    ($this->expire)();

    expect(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 0]);
});

test('BR-O04 the history and the audit name the system as the actor', function (): void {
    ($this->age)(49);

    ($this->expire)();

    expect(OrderStatusHistory::query()->latest('id')->first()?->only(['to_status', 'actor_type', 'actor_id', 'note']))->toBe([
        'to_status' => OrderStatus::Cancelled, 'actor_type' => ActorType::System, 'actor_id' => null, 'note' => 'انتهت مهلة تأكيد الطلب',
    ])
        ->and(AuditLog::query()->where('action', 'order.cancelled')->sole()->only(['actor_type', 'actor_id']))->toBe(['actor_type' => ActorType::System, 'actor_id' => null])
        ->and(AuditLog::query()->where('action', 'order.cancelled')->sole()->new_values)->toMatchArray(['cancelled_by' => 'system']);
});

test('BR-N02 the merchant is told with an order_expired notification', function (): void {
    ($this->age)(49);

    ($this->expire)();

    expect(MerchantNotification::query()->sole()->only(['type', 'data']))->toEqual([
        'type' => NotificationType::OrderExpired,
        'data' => ['order_number' => $this->order->number, 'customer_name' => $this->order->delivery_name, 'order_total' => $this->order->order_total],
    ]);
});

test('BR-N03 the customer gets the cancelled message with the reason', function (): void {
    ($this->age)(49);

    ($this->expire)();

    expect(OutboundMessage::query()->where('purpose', MessagePurpose::OrderStatus)->sole()->body)->toEndWith('السبب: انتهت مهلة تأكيد الطلب');
});

test('BR-INV07 an order not old enough yet stays pending', function (): void {
    ($this->age)(47);

    expect(($this->expire)())->toBeFalse()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(MerchantNotification::query()->count())->toBe(0);
});

test('D81 an order the merchant confirmed after it was listed is left alone', function (): void {
    ($this->age)(49);
    $this->order->forceFill(['status' => OrderStatus::Confirmed])->save();

    expect(($this->expire)())->toBeFalse()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Confirmed);
});

test('D81 nothing expires once the store turned the setting off', function (): void {
    ($this->age)(49);
    $this->tenant->settings->update(['pending_expiry_hours' => null]);

    expect(($this->expire)())->toBeFalse()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('BR-O03 an order with money collected is skipped and logged, never forced', function (): void {
    Log::spy();
    ($this->age)(49);
    $this->order->forceFill(['collected_amount' => '100.00'])->save();

    expect(($this->expire)())->toBeFalse()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(($this->stock)()['reserved'])->toBeGreaterThan(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'BR-O03')
        && $context === ['tenant_id' => $this->tenant->id, 'order_number' => $this->order->number, 'collected_amount' => '100.00']);
});

test('BR-T04 an order of another tenant is never visible here', function (): void {
    $theirs = $this->inTenant(fn (): Order => Order::factory()->create(), $this->otherTenant());

    expect(fn () => app(ExpirePendingOrder::class)->handle($theirs))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('D88 a pre-order never expires, however old', function (): void {
    $preorder = $this->placePreorder($this->customer, [$this->preorderableSku()->id => 5]);
    $preorder->forceFill(['placed_at' => now()->subDays(30)])->save();

    expect(app(ExpirePendingOrder::class)->handle($preorder))->toBeFalse()
        ->and($preorder->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(app(App\Modules\Orders\Queries\ExpirableOrdersQuery::class)->get()->pluck('id')->all())->not->toContain($preorder->id);
});
