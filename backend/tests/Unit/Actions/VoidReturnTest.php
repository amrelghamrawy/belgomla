<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Actions\VoidCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Actions\VoidReturn;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\ReturnAlreadyVoided;
use App\Modules\Orders\Exceptions\ReturnHasRefund;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| A delivered order of 6 cartons (20 pieces each) at 90.00 = 540.00; 2 cartons came back (180.00), so the SKU has
| 400 − 120 + 40 = 320 pieces on hand.
*/

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    $this->order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered), $this->owner->id);
    $this->line = OrderItem::query()->where('order_id', $this->order->id)->sole();

    $this->recordReturn = fn (bool $refund = false): OrderReturn => app(RecordReturn::class)->handle(
        $this->order,
        new ReturnData('مرتجع', [new ReturnLineData($this->line->id, 2)], refund: $refund),
        $this->owner->id,
    );
    $this->void = fn (OrderReturn $return, ?int $actor = null): OrderReturn => app(VoidReturn::class)->handle($return, 'سجلناه بالخطأ', $actor ?? $this->owner->id);
    $this->onHand = fn (): int => SkuInventory::query()->findOrFail($this->sku->id)->on_hand;
});

test('BR-R06 a voided return keeps its row with when, why and by whom', function (): void {
    $return = ($this->recordReturn)();
    $this->freezeSecond();

    ($this->void)($return);

    expect($return->refresh()->only(['void_reason', 'voided_by', 'total']))->toBe(['void_reason' => 'سجلناه بالخطأ', 'voided_by' => $this->owner->id, 'total' => '180.00'])
        ->and($return->voided_at?->equalTo(now()))->toBeTrue()
        ->and(OrderReturn::query()->count())->toBe(1);
});

test('BR-R06 voiding takes the goods off the stock again as a negative return movement', function (): void {
    $return = ($this->recordReturn)();

    ($this->void)($return);

    expect(($this->onHand)())->toBe(280)
        ->and(InventoryMovement::query()->where('type', MovementType::Return)->orderBy('id')->pluck('quantity')->all())->toBe([40, -40]);
});

test('BR-R06 voiding gives the returned total back and re-derives the collection status', function (): void {
    app(RecordCollection::class)->handle($this->order, new CollectionData('360.00'), $this->owner->id);
    $return = ($this->recordReturn)();
    expect($this->order->refresh()->collection_status)->toBe(CollectionStatus::Collected);

    ($this->void)($return);

    expect($this->order->refresh()->only(['returned_total', 'collected_amount', 'collection_status']))->toBe([
        'returned_total' => '0.00', 'collected_amount' => '360.00', 'collection_status' => CollectionStatus::PartiallyCollected,
    ]);
});

test('BR-R06 a voided return gives its quantity back to what is returnable', function (): void {
    ($this->void)(($this->recordReturn)());

    $again = app(RecordReturn::class)->handle($this->order, new ReturnData('مرتجع', [new ReturnLineData($this->line->id, 6)]), $this->owner->id);

    expect($again->number)->toBe(2);
});

test('BR-R06 D7 a return whose cash refund is live is voided only after the refund', function (): void {
    app(RecordCollection::class)->handle($this->order, new CollectionData('540.00'), $this->owner->id);
    $return = ($this->recordReturn)(refund: true);

    expect(fn (): OrderReturn => ($this->void)($return))->toThrow(function (ReturnHasRefund $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'return_has_refund', ['refund_amount' => '180.00']]);
    });

    expect($return->refresh()->voided_at)->toBeNull()
        ->and(($this->onHand)())->toBe(320);
});

test('BR-R06 once the refund is voided the return can be voided, and the order is collected again in full', function (): void {
    app(RecordCollection::class)->handle($this->order, new CollectionData('540.00'), $this->owner->id);
    $return = ($this->recordReturn)(refund: true);
    app(VoidCollection::class)->handle(CashCollection::query()->where('kind', CollectionKind::Refund)->sole(), 'لم يُرد المبلغ', $this->owner->id);

    ($this->void)($return);

    expect($this->order->refresh()->only(['returned_total', 'collected_amount', 'collection_status']))->toBe([
        'returned_total' => '0.00', 'collected_amount' => '540.00', 'collection_status' => CollectionStatus::Collected,
    ]);
});

test('D76 a return whose goods were sold or reserved again cannot be voided', function (): void {
    $return = ($this->recordReturn)();
    DB::transaction(fn () => app(StockLedger::class)->reserve($this->sku, 300));

    expect(fn (): OrderReturn => ($this->void)($return))->toThrow(function (InsufficientStock $e): void {
        expect([$e->status, $e->errorCode, $e->items])->toBe([422, 'insufficient_stock', [['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'available' => 20]]]);
    });

    expect($return->refresh()->voided_at)->toBeNull()
        ->and($this->order->refresh()->returned_total)->toBe('180.00');
});

test('BR-R06 a return is voided only once', function (): void {
    $return = ($this->recordReturn)();
    ($this->void)($return);
    AuditLog::query()->delete();

    expect(fn (): OrderReturn => ($this->void)($return))->toThrow(function (ReturnAlreadyVoided $e) use ($return): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'already_voided', ['voided_at' => $return->refresh()->voided_at?->toIso8601String()]]);
    });

    expect(AuditLog::query()->count())->toBe(0)
        ->and(($this->onHand)())->toBe(280);
});

test('BR-O04 the history gets a note for the void', function (): void {
    ($this->void)(($this->recordReturn)());

    expect(OrderStatusHistory::query()->latest('id')->first()?->only(['from_status', 'to_status', 'actor_type', 'note']))->toBe([
        'from_status' => OrderStatus::Delivered, 'to_status' => OrderStatus::Delivered, 'actor_type' => ActorType::TenantUser, 'note' => 'إلغاء مرتجع #1',
    ]);
});

test('BR-L a void is audited as order.return_voided', function (): void {
    $return = ($this->recordReturn)();
    AuditLog::query()->delete();
    $this->freezeSecond();

    ($this->void)($return);

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'order.return_voided',
        'entity_type' => 'order_returns',
        'entity_id' => $return->id,
        'old_values' => ['voided_at' => null],
        'new_values' => ['voided_at' => now()->toIso8601String(), 'void_reason' => 'سجلناه بالخطأ', 'order_number' => $this->order->number, 'number' => 1, 'total' => '180.00'],
    ]);
});

test('the voided return carries the order with its new totals', function (): void {
    $voided = ($this->void)(($this->recordReturn)());

    expect($voided->order->returned_total)->toBe('0.00');
});

test('a failing void leaves the return, the stock and the order untouched', function (): void {
    $return = ($this->recordReturn)();

    expect(fn (): OrderReturn => ($this->void)($return, 999_999))->toThrow(QueryException::class);

    expect($return->refresh()->voided_at)->toBeNull()
        ->and(($this->onHand)())->toBe(320)
        ->and($this->order->refresh()->returned_total)->toBe('180.00');
});
