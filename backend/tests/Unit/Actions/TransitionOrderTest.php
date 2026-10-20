<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\OrderEditRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Enums\DiscountType;
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

test('BR-C06 a delivered order falls due its customer\'s payment terms later', function (): void {
    $this->freezeSecond();
    App\Modules\Customers\Models\Customer::query()->whereKey($this->order->customer_id)->update(['payment_terms_days' => 30]);

    $order = ($this->move)(OrderStatus::Delivered);

    expect($order->refresh()->due_at?->equalTo(now()->addDays(30)))->toBeTrue();
});

test('BR-C06 without payment terms a delivered order is due on delivery', function (): void {
    $this->freezeSecond();

    $order = ($this->move)(OrderStatus::Delivered);

    expect($order->refresh()->due_at?->equalTo($order->delivered_at))->toBeTrue();
});

test('BR-C06 the due date is set on delivery only', function (): void {
    expect(($this->move)(OrderStatus::Confirmed)->refresh()->due_at)->toBeNull();
});

/*
| BR-O13, D86: a second line of 10 cartons of B at 85.00 is added to the order: 6 × 90.00 + 10 × 85.00 = 1390.00.
*/
describe('partial delivery', function (): void {
    beforeEach(function (): void {
        $this->skuB = $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب');
        $this->order = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 6, $this->skuB->id => 10]);
        app(TenantContext::class)->set($this->tenant);
        [$this->lineA, $this->lineB] = OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->all();
        $this->deliver = fn (array $delivered, bool $collect = false): Order => app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered, null, $collect, $delivered), $this->owner->id);
        $this->stock = fn (): array => SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'reserved']);
        $this->stockB = fn (): array => SkuInventory::query()->findOrFail($this->skuB->id)->only(['on_hand', 'reserved']);
    });

    test('BR-O13 BR-INV05 the delivered units are deducted and the rest released', function (): void {
        ($this->deliver)([$this->lineA->id => 4]);

        expect(($this->stock)())->toBe(['on_hand' => 320, 'reserved' => 120])
            ->and(($this->stockB)())->toBe(['on_hand' => 200, 'reserved' => 0]);
    });

    test('BR-O13 the sale movements match what was delivered', function (): void {
        ($this->deliver)([$this->lineA->id => 4]);

        expect(InventoryMovement::query()->where('order_id', $this->order->id)->where('type', MovementType::Sale)->orderBy('sku_id')->pluck('quantity', 'sku_id')->all())
            ->toBe([$this->sku->id => -80, $this->skuB->id => -200]);
    });

    test('BR-O13 a line delivered with nothing writes no sale movement', function (): void {
        ($this->deliver)([$this->lineA->id => 0]);

        expect(InventoryMovement::query()->where('order_id', $this->order->id)->pluck('sku_id')->all())->toBe([$this->skuB->id])
            ->and(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 120]);
    });

    test('BR-O13 BR-O08 each line keeps its delivered quantity and the order is charged for it', function (): void {
        $order = ($this->deliver)([$this->lineA->id => 4]);

        expect($this->lineA->refresh()->only(['quantity', 'delivered_quantity', 'unit_price', 'line_total']))->toBe(['quantity' => 6, 'delivered_quantity' => 4, 'unit_price' => '90.00', 'line_total' => '360.00'])
            ->and($this->lineB->refresh()->only(['quantity', 'delivered_quantity', 'line_total']))->toBe(['quantity' => 10, 'delivered_quantity' => 10, 'line_total' => '850.00'])
            ->and($order->refresh()->only(['items_total', 'order_total']))->toBe(['items_total' => '1210.00', 'order_total' => '1210.00']);
    });

    test('BR-O13 every short line is kept as a delivered_short change by the user', function (): void {
        ($this->deliver)([$this->lineA->id => 4]);

        expect(OrderItemChange::query()->sole()->only(['order_item_id', 'change', 'old_quantity', 'new_quantity', 'unit_price_before', 'unit_price_after', 'actor_id']))->toBe([
            'order_item_id' => $this->lineA->id, 'change' => OrderItemChangeType::DeliveredShort, 'old_quantity' => 6, 'new_quantity' => 4,
            'unit_price_before' => '90.00', 'unit_price_after' => '90.00', 'actor_id' => $this->owner->id,
        ]);
    });

    test('BR-O13 the history notes the partial delivery and the audit lists the short lines', function (): void {
        AuditLog::query()->delete();

        ($this->deliver)([$this->lineA->id => 4]);

        expect(OrderStatusHistory::query()->where('order_id', $this->order->id)->latest('id')->firstOrFail()->note)->toBe('تسليم جزئي')
            ->and(AuditLog::query()->where('action', 'order.status_changed')->sole()->new_values)->toEqual([
                'status' => 'delivered',
                'delivered_short' => [['order_item_id' => $this->lineA->id, 'ordered' => 6, 'delivered' => 4]],
                'order_total' => '1210.00',
            ]);
    });

    test('D86 a percent order discount is taken off what was delivered', function (): void {
        $this->order->forceFill(['discount_type' => DiscountType::Percent, 'discount_value' => '10', 'discount_reason' => 'عميل دائم', 'discount_amount' => '139.00', 'order_total' => '1251.00'])->save();

        expect(($this->deliver)([$this->lineA->id => 4])->refresh()->only(['items_total', 'discount_amount', 'order_total']))->toBe(['items_total' => '1210.00', 'discount_amount' => '121.00', 'order_total' => '1089.00']);
    });

    test('D86 an EGP order discount is capped so the order still costs 0.01', function (): void {
        $this->order->forceFill(['discount_type' => DiscountType::Amount, 'discount_value' => '1000', 'discount_reason' => 'هدية', 'discount_amount' => '1000.00', 'order_total' => '390.00'])->save();

        expect(($this->deliver)([$this->lineA->id => 1, $this->lineB->id => 0])->refresh()->only(['items_total', 'discount_amount', 'order_total']))->toBe(['items_total' => '90.00', 'discount_amount' => '89.99', 'order_total' => '0.01']);
    });

    test('BR-C03 BR-C04 collecting the full amount collects what was delivered', function (): void {
        $order = ($this->deliver)([$this->lineA->id => 4], collect: true);

        expect($order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '1210.00', 'collection_status' => CollectionStatus::Collected]);
    });

    test('BR-C03 the collection status is derived again from what was delivered', function (): void {
        $this->order->forceFill(['collected_amount' => '1210.00', 'collection_status' => CollectionStatus::PartiallyCollected])->save();

        expect(($this->deliver)([$this->lineA->id => 4])->refresh()->collection_status)->toBe(CollectionStatus::Collected);
    });

    test('BR-C03 a short delivery below what was collected is refused and nothing changes', function (): void {
        $this->order->forceFill(['collected_amount' => '1300.00', 'collection_status' => CollectionStatus::PartiallyCollected])->save();

        expect(fn (): Order => ($this->deliver)([$this->lineA->id => 4]))->toThrow(function (OrderEditRefused $e): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'below_collected', ['collected_amount' => '1300.00', 'order_total' => '1210.00']]);
        });

        expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
            ->and(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 240])
            ->and(OrderItemChange::query()->count())->toBe(0);
    });

    test('BR-O13 more than ordered is a validation error on the line', function (): void {
        expect(fn (): Order => ($this->deliver)([$this->lineB->id => 10, $this->lineA->id => 7]))->toThrow(function (OrderEditRefused $e): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'validation_failed', ['delivered_quantities.1.quantity' => ['الكمية المسلّمة أكبر من المطلوبة (6)']]]);
        });

        expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
    });

    test('BR-O13 a line of another order is a validation error on its field', function (): void {
        $other = OrderItem::factory()->create();

        expect(fn (): Order => ($this->deliver)([$other->id => 1]))->toThrow(function (OrderEditRefused $e): void {
            expect($e->details)->toBe(['delivered_quantities.0.order_item_id' => ['البند غير موجود في هذا الطلب']]);
        });
    });

    test('D86 delivering nothing at all is refused: the order is cancelled instead', function (): void {
        expect(fn (): Order => ($this->deliver)([$this->lineA->id => 0, $this->lineB->id => 0]))->toThrow(function (OrderEditRefused $e): void {
            expect([$e->status, $e->details])->toBe([422, ['delivered_quantities' => ['لم يتم تسليم أي صنف، ألغِ الطلب بدلًا من ذلك']]]);
        });
    });

    test('BR-O13 without delivered quantities everything was delivered as before', function (): void {
        $order = app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered), $this->owner->id);

        expect($order->refresh()->order_total)->toBe('1390.00')
            ->and([$this->lineA->refresh()->delivered_quantity, $this->lineB->refresh()->delivered_quantity])->toBe([6, 10])
            ->and(OrderItemChange::query()->count())->toBe(0)
            ->and(OrderStatusHistory::query()->where('order_id', $this->order->id)->latest('id')->firstOrFail()->note)->toBeNull();
    });

    test('BR-O13 a line delivered by its full quantity is no short delivery', function (): void {
        ($this->deliver)([$this->lineA->id => 6]);

        expect(OrderItemChange::query()->count())->toBe(0)
            ->and($this->order->refresh()->order_total)->toBe('1390.00');
    });

    test('BR-O12 a removed line is neither deducted nor released again', function (): void {
        $this->lineB->forceFill(['removed_at' => now()])->save();
        SkuInventory::query()->whereKey($this->skuB->id)->update(['reserved' => 0]);
        $this->order->forceFill(['items_total' => '540.00', 'order_total' => '540.00'])->save();

        ($this->deliver)([]);

        expect(($this->stockB)())->toBe(['on_hand' => 400, 'reserved' => 0])
            ->and($this->lineB->refresh()->delivered_quantity)->toBeNull();
    });
});

test('BR-O12 BR-INV04 cancelling releases the live lines only', function (): void {
    $skuB = $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب');
    $order = $this->placeOrder($this->makeCustomer(), [$this->sku->id => 5, $skuB->id => 5]);
    app(TenantContext::class)->set($this->tenant);
    OrderItem::query()->where('order_id', $order->id)->where('sku_id', $skuB->id)->update(['removed_at' => now()]);
    SkuInventory::query()->whereKey($skuB->id)->update(['reserved' => 0]);

    app(CancelOrder::class)->handle($order, new CancelData('نفد'), CancelledBy::TenantUser, $this->owner->id);

    expect(SkuInventory::query()->findOrFail($skuB->id)->reserved)->toBe(0)
        ->and(($this->stock)())->toBe(['on_hand' => 400, 'reserved' => 120]);
});
