<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    AuditLog::query()->delete();

    $this->move = fn (OrderStatus $to, ?string $note = null, bool $collect = false): Order => app(TransitionOrder::class)->handle($this->order, new TransitionData($to, $note, $collect), $this->owner->id);
    $this->stock = fn (): array => SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'reserved']);
});

test('BR-O01 the merchant confirms a pending order, stamped and recorded in the history with the note', function (): void {
    $this->freezeSecond();

    $order = ($this->move)(OrderStatus::Confirmed, 'تم التأكيد بالهاتف');

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->confirmed_at?->equalTo(now()))->toBeTrue()
        ->and(OrderStatusHistory::query()->latest('id')->first()?->only(['from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
            'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Confirmed, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id, 'note' => 'تم التأكيد بالهاتف',
        ]);
});

test('BR-L a transition is audited as order.status_changed', function (): void {
    ($this->move)(OrderStatus::Confirmed, 'تم التأكيد');

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'order.status_changed', 'entity_type' => 'orders', 'entity_id' => $this->order->id,
        'old_values' => ['status' => 'pending'], 'new_values' => ['status' => 'confirmed', 'note' => 'تم التأكيد'],
    ]);
});

test('BR-O01 steps may be skipped', function (): void {
    $order = ($this->move)(OrderStatus::OutForDelivery);

    expect($order->refresh()->status)->toBe(OrderStatus::OutForDelivery)
        ->and($order->confirmed_at)->not->toBeNull();
});

test('BR-O01 a confirmed order keeps its first confirmation time', function (): void {
    $this->travelTo(now()->subHour());
    ($this->move)(OrderStatus::Confirmed);
    $confirmedAt = $this->order->refresh()->confirmed_at;
    $this->travelBack();

    expect(($this->move)(OrderStatus::Preparing)->refresh()->confirmed_at?->equalTo($confirmedAt))->toBeTrue();
});

test('BR-INV03 a forward move before delivery keeps the reservation and writes no movement', function (): void {
    ($this->move)(OrderStatus::OutForDelivery);

    expect(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 120])
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV05 delivery takes the units out of on hand and reserved with a sale movement of the order', function (): void {
    $this->freezeSecond();

    $order = ($this->move)(OrderStatus::Delivered);

    expect(($this->stock)())->toBe(['on_hand' => 280, 'reserved' => 0])
        ->and($order->refresh()->delivered_at?->equalTo(now()))->toBeTrue()
        ->and(InventoryMovement::query()->sole()->only(['sku_id', 'type', 'quantity', 'on_hand_after', 'order_id', 'actor_type', 'actor_id']))->toBe([
            'sku_id' => $this->sku->id, 'type' => MovementType::Sale, 'quantity' => -120, 'on_hand_after' => 280, 'order_id' => $this->order->id,
            'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id,
        ]);
});

test('BR-INV05 every line of a delivered order is deducted', function (): void {
    $other = $this->orderableSku(['moq' => 1], name: 'كراسة');
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5, $other->id => 2]);

    ($this->move)(OrderStatus::Delivered);

    expect(InventoryMovement::query()->where('order_id', $this->order->id)->orderBy('sku_id')->pluck('quantity', 'sku_id')->all())
        ->toBe([$this->sku->id => -100, $other->id => -40]);
});

test('BR-C04 delivered with collect_full_amount asks Collections to record the remaining balance', function (): void {
    $payments = new class implements CollectsPayments
    {
        /** @var list<array{0: int, 1: int|null}> */
        public array $calls = [];

        public function collectRemaining(Order $order, ?int $actorId): void
        {
            $this->calls[] = [$order->id, $actorId];
        }
    };
    app()->instance(CollectsPayments::class, $payments);

    ($this->move)(OrderStatus::Delivered, collect: true);

    expect($payments->calls)->toBe([[$this->order->id, $this->owner->id]]);
});

test('BR-C04 delivered with collect_full_amount records the order total as collected in the same step', function (): void {
    $order = ($this->move)(OrderStatus::Delivered, collect: true);

    expect($order->only(['status', 'collected_amount', 'collection_status']))->toBe([
        'status' => OrderStatus::Delivered, 'collected_amount' => $order->order_total, 'collection_status' => CollectionStatus::Collected,
    ])->and(CashCollection::query()->sole()->only(['order_id', 'amount', 'recorded_by']))->toBe([
        'order_id' => $this->order->id, 'amount' => $order->order_total, 'recorded_by' => $this->owner->id,
    ])->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['collection.recorded', 'order.status_changed']);
});

test('BR-C04 a failing collection rolls the delivery back', function (): void {
    expect(fn () => app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered, null, true), 999_999))
        ->toThrow(QueryException::class);

    expect($this->order->refresh()->only(['status', 'collected_amount']))->toBe(['status' => OrderStatus::Pending, 'collected_amount' => '0.00'])
        ->and(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 120])
        ->and(CashCollection::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-C04 without collect_full_amount nothing is collected', function (): void {
    $payments = Mockery::mock(CollectsPayments::class);
    $payments->shouldNotReceive('collectRemaining');
    app()->instance(CollectsPayments::class, $payments);

    ($this->move)(OrderStatus::Delivered);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Delivered);
});

test('BR-O01 an invalid move changes nothing', function (OrderStatus $from, OrderStatus $to): void {
    $this->order->forceFill(['status' => $from])->save();
    $history = OrderStatusHistory::query()->count();

    expect(fn () => ($this->move)($to))->toThrow(InvalidOrderTransition::class);

    expect($this->order->refresh()->status)->toBe($from)
        ->and(OrderStatusHistory::query()->count())->toBe($history)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 120]);
})->with([
    'backwards' => [OrderStatus::Preparing, OrderStatus::Confirmed],
    'onto itself' => [OrderStatus::Confirmed, OrderStatus::Confirmed],
    'out of delivered' => [OrderStatus::Delivered, OrderStatus::Preparing],
    'out of cancelled' => [OrderStatus::Cancelled, OrderStatus::Confirmed],
]);

test('BR-O01 the guard reads the status under lock, not the stale model it was given', function (): void {
    $stale = Order::query()->findOrFail($this->order->id);
    ($this->move)(OrderStatus::Delivered);

    expect(fn () => app(TransitionOrder::class)->handle($stale, new TransitionData(OrderStatus::Delivered), $this->owner->id))
        ->toThrow(InvalidOrderTransition::class);

    expect(InventoryMovement::query()->count())->toBe(1);
});
