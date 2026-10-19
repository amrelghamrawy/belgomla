<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\RefundRequired;
use App\Modules\Orders\Exceptions\ReturnExceedsDelivered;
use App\Modules\Orders\Exceptions\ReturnNotAllowed;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
| One delivered order of two lines: 6 cartons of A at 90.00 (540.00) and 10 cartons of B at the 10+ tier price 85.00
| (850.00), order total 1390.00. Cartons hold 20 pieces, so the delivery left A at 280 and B at 200 pieces on hand.
*/

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer();
    $this->a = $this->orderableSku(['code' => 'A-1'], name: 'كتاب أ');
    $this->b = $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب');
    $this->order = $this->placeOrder($this->customer, [$this->a->id => 6, $this->b->id => 10]);
    app(TenantContext::class)->set($this->tenant);
    app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered), $this->owner->id);
    $this->order->refresh();
    [$this->lineA, $this->lineB] = OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->all();
    AuditLog::query()->delete();
    MerchantNotification::query()->delete();

    $this->record = fn (array $lines, bool $refund = false, ?int $actor = null): OrderReturn => app(RecordReturn::class)->handle(
        $this->order,
        new ReturnData('مرتجع نهاية الموسم', array_map(fn (array $line): ReturnLineData => new ReturnLineData($line[0]->id, $line[1]), $lines), refund: $refund),
        $actor ?? $this->owner->id,
    );
    $this->collect = fn (string $amount): CashCollection => app(RecordCollection::class)->handle($this->order, new CollectionData($amount), $this->owner->id);
    $this->onHand = fn ($sku): int => SkuInventory::query()->findOrFail($sku->id)->on_hand;
});

test('BR-R01 only a delivered order takes a return', function (OrderStatus $status): void {
    $this->order->forceFill(['status' => $status])->save();

    expect(fn (): OrderReturn => ($this->record)([[$this->lineA, 1]]))->toThrow(function (ReturnNotAllowed $e) use ($status): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'return_not_allowed', ['status' => $status->value]]);
    });

    expect(OrderReturn::query()->count())->toBe(0)
        ->and(($this->onHand)($this->a))->toBe(280);
})->with([
    'pending' => [OrderStatus::Pending],
    'confirmed' => [OrderStatus::Confirmed],
    'preparing' => [OrderStatus::Preparing],
    'out for delivery' => [OrderStatus::OutForDelivery],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('BR-R02 a line cannot bring back more than was delivered, and every offending line is listed', function (): void {
    expect(fn (): OrderReturn => ($this->record)([[$this->lineA, 7], [$this->lineB, 10]]))->toThrow(function (ReturnExceedsDelivered $e): void {
        expect([$e->status, $e->errorCode, $e->items])->toBe([422, 'return_exceeds_delivered', [
            ['order_item_id' => $this->lineA->id, 'requested' => 7, 'returnable' => 6, 'reason' => 'return_exceeds_delivered'],
        ]]);
    });

    expect(OrderReturn::query()->count())->toBe(0);
});

test('BR-R02 earlier live returns count against what is returnable', function (): void {
    ($this->record)([[$this->lineA, 4]]);

    expect(fn (): OrderReturn => ($this->record)([[$this->lineA, 3]]))->toThrow(function (ReturnExceedsDelivered $e): void {
        expect($e->items)->toBe([['order_item_id' => $this->lineA->id, 'requested' => 3, 'returnable' => 2, 'reason' => 'return_exceeds_delivered']]);
    });
});

test('BR-T04 BR-R02 a line of another order is never returnable', function (): void {
    $other = OrderItem::factory()->for(Order::factory()->delivered())->create(['sku_id' => $this->a->id]);

    expect(fn (): OrderReturn => ($this->record)([[$other, 1]]))->toThrow(function (ReturnExceedsDelivered $e) use ($other): void {
        expect($e->items)->toBe([['order_item_id' => $other->id, 'requested' => 1, 'returnable' => 0, 'reason' => 'return_exceeds_delivered']]);
    });
});

test('BR-R03 every returned line goes back into stock in base units as a return movement of the order', function (): void {
    ($this->record)([[$this->lineA, 2], [$this->lineB, 5]]);

    expect(($this->onHand)($this->a))->toBe(320)
        ->and(($this->onHand)($this->b))->toBe(300)
        ->and(InventoryMovement::query()->where('type', MovementType::Return)->orderBy('sku_id')->get()->map->only(['sku_id', 'quantity', 'order_id', 'actor_type', 'actor_id'])->all())->toBe([
            ['sku_id' => $this->a->id, 'quantity' => 40, 'order_id' => $this->order->id, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id],
            ['sku_id' => $this->b->id, 'quantity' => 100, 'order_id' => $this->order->id, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id],
        ]);
});

test('D74 a return is priced at the line\'s snapshot price, a tier price staying the tier price even after the catalog changed', function (): void {
    $this->b->forceFill(['base_price' => '120.00'])->save();
    SkuPriceTier::query()->where('sku_id', $this->b->id)->delete();

    $return = ($this->record)([[$this->lineB, 2]]);

    expect($return->total)->toBe('170.00')
        ->and($return->items->sole()->only(['order_item_id', 'quantity', 'quantity_base', 'unit_price', 'line_total']))->toBe([
            'order_item_id' => $this->lineB->id, 'quantity' => 2, 'quantity_base' => 40, 'unit_price' => '85.00', 'line_total' => '170.00',
        ]);
});

test('BR-R05 the return raises the order\'s returned total and lowers its net total', function (): void {
    ($this->record)([[$this->lineA, 2]]);
    ($this->record)([[$this->lineB, 1]]);

    expect($this->order->refresh()->only(['order_total', 'returned_total']))->toBe(['order_total' => '1390.00', 'returned_total' => '265.00'])
        ->and($this->order->netTotal())->toBe('1125.00');
});

test('BR-C03 BR-R05 the collection status is derived again from the net total', function (string $collected, array $lines, CollectionStatus $status): void {
    ($this->collect)($collected);

    ($this->record)(array_map(fn (array $line): array => [$line[0] === 'a' ? $this->lineA : $this->lineB, $line[1]], $lines));

    expect($this->order->refresh()->collection_status)->toBe($status);
})->with([
    'a deposit that now covers the net total' => ['540.00', [['b', 10]], CollectionStatus::Collected],
    'a deposit still short of the net total' => ['500.00', [['b', 10]], CollectionStatus::PartiallyCollected],
    'a piaster collected stays partly collected' => ['0.01', [['a', 1]], CollectionStatus::PartiallyCollected],
]);

test('BR-R05 an order returned in full owes nothing', function (): void {
    ($this->record)([[$this->lineA, 6], [$this->lineB, 10]]);

    expect($this->order->refresh()->only(['returned_total', 'collected_amount', 'collection_status']))->toBe([
        'returned_total' => '1390.00', 'collected_amount' => '0.00', 'collection_status' => CollectionStatus::Collected,
    ]);
});

test('BR-R04 an order collected beyond its new net total needs the merchant to confirm the cash refund, and nothing is saved', function (): void {
    ($this->collect)('1390.00');

    expect(fn (): OrderReturn => ($this->record)([[$this->lineA, 2]]))->toThrow(function (RefundRequired $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'refund_required', ['refund_amount' => '180.00']]);
    });

    expect(OrderReturn::query()->count())->toBe(0)
        ->and($this->order->refresh()->returned_total)->toBe('0.00')
        ->and(($this->onHand)($this->a))->toBe(280)
        ->and(InventoryMovement::query()->where('type', MovementType::Return)->count())->toBe(0);
});

test('BR-R04 a confirmed refund records a refund collection tied to the return and lowers what was collected', function (): void {
    ($this->collect)('1390.00');

    $return = ($this->record)([[$this->lineA, 2]], refund: true);

    expect($return->refund_amount)->toBe('180.00')
        ->and(CashCollection::query()->where('kind', CollectionKind::Refund)->sole()->only(['order_id', 'order_return_id', 'amount', 'recorded_by']))->toBe([
            'order_id' => $this->order->id, 'order_return_id' => $return->id, 'amount' => '180.00', 'recorded_by' => $this->owner->id,
        ])
        ->and($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '1210.00', 'collection_status' => CollectionStatus::Collected])
        ->and(AuditLog::query()->pluck('action')->sort()->values()->all())->toBe(['collection.recorded', 'collection.refunded', 'order.returned']);
});

test('BR-R04 a refund confirmation is ignored when nothing was over-collected', function (): void {
    ($this->collect)('200.00');

    $return = ($this->record)([[$this->lineA, 2]], refund: true);

    expect($return->refund_amount)->toBe('0.00')
        ->and(CashCollection::query()->where('kind', CollectionKind::Refund)->count())->toBe(0)
        ->and($this->order->refresh()->collected_amount)->toBe('200.00');
});

test('the returns of an order are numbered 1, 2, …', function (): void {
    $first = ($this->record)([[$this->lineA, 1]]);
    $second = ($this->record)([[$this->lineA, 1]]);

    expect([$first->number, $second->number])->toBe([1, 2]);
});

test('BR-O04 the history gets a note for the return without a status change', function (): void {
    ($this->record)([[$this->lineA, 1]]);

    expect(OrderStatusHistory::query()->latest('id')->first()?->only(['from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
        'from_status' => OrderStatus::Delivered, 'to_status' => OrderStatus::Delivered, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id, 'note' => 'مرتجع #1',
    ]);
});

test('BR-N02 the merchant who recorded the return is not notified', function (): void {
    ($this->record)([[$this->lineA, 1]]);

    expect(MerchantNotification::query()->count())->toBe(0);
});

test('BR-L a return is audited as order.returned', function (): void {
    $return = ($this->record)([[$this->lineA, 2], [$this->lineB, 1]]);

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'order.returned',
        'entity_type' => 'order_returns',
        'entity_id' => $return->id,
        'old_values' => null,
        'new_values' => [
            'order_id' => $this->order->id, 'order_number' => $this->order->number, 'number' => 1, 'total' => '265.00', 'refund_amount' => '0.00', 'refund_method' => null,
            'reason' => 'مرتجع نهاية الموسم',
            'items' => [['order_item_id' => $this->lineA->id, 'quantity' => 2], ['order_item_id' => $this->lineB->id, 'quantity' => 1]],
        ],
    ]);
});

test('the return carries its order, its lines with their order lines, and defaults returned_at to now', function (): void {
    $this->freezeSecond();

    $return = ($this->record)([[$this->lineA, 1]]);

    expect($return->relationLoaded('order'))->toBeTrue()
        ->and($return->order->returned_total)->toBe('90.00')
        ->and($return->items->sole()->relationLoaded('orderItem'))->toBeTrue()
        ->and($return->returned_at->equalTo(now()))->toBeTrue()
        ->and($return->recorded_by)->toBe($this->owner->id);
});

test('a failing return leaves the order, the stock and the audit trail untouched', function (): void {
    expect(fn (): OrderReturn => ($this->record)([[$this->lineA, 2]], actor: 999_999))->toThrow(QueryException::class);

    expect(OrderReturn::query()->count())->toBe(0)
        ->and($this->order->refresh()->returned_total)->toBe('0.00')
        ->and(($this->onHand)($this->a))->toBe(280)
        ->and(AuditLog::query()->count())->toBe(0);
});
