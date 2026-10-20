<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Actions\ApplyOrderDiscount;
use App\Modules\Orders\Actions\EditOrderLines;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Data\EditedOrder;
use App\Modules\Orders\Data\LineDiscountData;
use App\Modules\Orders\Data\OrderEditData;
use App\Modules\Orders\Data\OrderEditLineData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\OrderEditRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Exceptions\DiscountTooLarge;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

/*
| One pending order of two lines (cartons of 20, MOQ 5, 90.00, 10+ → 85.00, 20 cartons in stock each): 6 cartons of A
| at 90.00 (540.00, 120 pieces reserved) and 10 cartons of B at 85.00 (850.00, 200 reserved); total 1390.00. A third
| SKU C is in stock and not on the order.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'محمود']);
    $this->skuA = $this->orderableSku(['code' => 'A-1']);
    $this->skuB = $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب');
    $this->skuC = $this->orderableSku(['code' => 'C-1'], name: 'كتاب ج');
    $this->order = $this->placeOrder($this->customer, [$this->skuA->id => 6, $this->skuB->id => 10]);
    app(TenantContext::class)->set($this->tenant);
    [$this->lineA, $this->lineB] = OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->all();
    AuditLog::query()->delete();
    OutboundMessage::query()->delete();

    $this->edit = fn (array $lines, ?string $note = null): EditedOrder => app(EditOrderLines::class)->handle(
        $this->order,
        new OrderEditData(array_map(fn (array $line): OrderEditLineData => new OrderEditLineData(...$line), $lines), $note),
        $this->owner->id,
    );
    // The order as it is: [order item id, sku id, quantity] per line.
    $this->a = fn (int $quantity = 6): array => [$this->lineA->id, $this->skuA->id, $quantity];
    $this->b = fn (int $quantity = 10): array => [$this->lineB->id, $this->skuB->id, $quantity];
    $this->reserved = fn (Sku $sku): int => SkuInventory::query()->findOrFail($sku->id)->reserved;
    $this->totals = fn (): array => Order::query()->findOrFail($this->order->id)->only(['items_total', 'discount_amount', 'order_total']);
    $this->expectUnchanged = function (): void {
        expect(($this->totals)())->toBe(['items_total' => '1390.00', 'discount_amount' => '0.00', 'order_total' => '1390.00'])
            ->and([($this->reserved)($this->skuA), ($this->reserved)($this->skuB), ($this->reserved)($this->skuC)])->toBe([120, 200, 0])
            ->and(OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->map->only(['quantity', 'unit_price', 'removed_at'])->all())->toBe([
                ['quantity' => 6, 'unit_price' => '90.00', 'removed_at' => null],
                ['quantity' => 10, 'unit_price' => '85.00', 'removed_at' => null],
            ])
            ->and(OrderItemChange::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    };
});

test('BR-O12 BR-INV03 adding a SKU reserves it and adds a line snapshotted like checkout', function (): void {
    $edited = ($this->edit)([($this->a)(), ($this->b)(), [null, $this->skuC->id, 5]]);

    $added = OrderItem::query()->where('order_id', $this->order->id)->where('sku_id', $this->skuC->id)->sole();

    expect(($this->reserved)($this->skuC))->toBe(100)
        ->and($added->only(['product_name', 'sku_code', 'quantity', 'quantity_base', 'list_unit_price', 'unit_price', 'line_total']))->toBe([
            'product_name' => 'كتاب ج', 'sku_code' => 'C-1', 'quantity' => 5, 'quantity_base' => 100, 'list_unit_price' => '90.00', 'unit_price' => '90.00', 'line_total' => '450.00',
        ])
        ->and($edited->order->only(['items_total', 'order_total']))->toBe(['items_total' => '1840.00', 'order_total' => '1840.00']);
});

test('BR-O12 BR-INV04 removing a line releases its reservation and keeps the line for history, out of the totals', function (): void {
    $this->freezeTime();

    $edited = ($this->edit)([($this->a)()]);

    expect(($this->reserved)($this->skuB))->toBe(0)
        ->and($this->lineB->refresh()->removed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($this->lineB->only(['quantity', 'line_total']))->toBe(['quantity' => 10, 'line_total' => '850.00'])
        ->and($edited->order->only(['items_total', 'order_total']))->toBe(['items_total' => '540.00', 'order_total' => '540.00']);
});

test('BR-O12 increasing a quantity reserves only the difference', function (): void {
    ($this->edit)([($this->a)(8), ($this->b)()]);

    expect(($this->reserved)($this->skuA))->toBe(160)
        ->and($this->lineA->refresh()->only(['quantity', 'quantity_base', 'line_total']))->toBe(['quantity' => 8, 'quantity_base' => 160, 'line_total' => '720.00']);
});

test('BR-O12 decreasing a quantity releases only the difference and re-prices below the tier', function (): void {
    $edited = ($this->edit)([($this->a)(), ($this->b)(7)]);

    expect(($this->reserved)($this->skuB))->toBe(140)
        ->and($this->lineB->refresh()->only(['quantity', 'list_unit_price', 'unit_price', 'tier_min_quantity', 'line_total']))->toBe([
            'quantity' => 7, 'list_unit_price' => '90.00', 'unit_price' => '90.00', 'tier_min_quantity' => null, 'line_total' => '630.00',
        ])
        ->and($edited->order->order_total)->toBe('1170.00');
});

test('BR-PR04 a quantity that reaches a tier gets the tier price', function (): void {
    ($this->edit)([($this->a)(12), ($this->b)()]);

    expect($this->lineA->refresh()->only(['unit_price', 'tier_min_quantity', 'line_total']))->toBe(['unit_price' => '85.00', 'tier_min_quantity' => 10, 'line_total' => '1020.00']);
});

test('BR-O05 an unchanged line keeps its snapshot price after a catalog price change', function (): void {
    SkuPriceTier::query()->where('sku_id', $this->skuB->id)->update(['unit_price' => '80.00']);
    $this->skuA->forceFill(['base_price' => '95.00'])->save();

    ($this->edit)([($this->a)(7), ($this->b)()]);

    expect($this->lineB->refresh()->unit_price)->toBe('85.00')
        ->and($this->lineA->refresh()->unit_price)->toBe('95.00');
});

test('D85 a line keeps its manual discount on the new list price', function (): void {
    app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([new LineDiscountData($this->lineA->id, DiscountType::Percent, '10')]), $this->owner->id);

    ($this->edit)([($this->a)(12), ($this->b)()]);

    expect($this->lineA->refresh()->only(['list_unit_price', 'manual_discount_type', 'manual_discount_value', 'unit_price', 'line_total']))->toBe([
        'list_unit_price' => '85.00', 'manual_discount_type' => DiscountType::Percent, 'manual_discount_value' => '10.00', 'unit_price' => '76.50', 'line_total' => '918.00',
    ]);
});

test('D85 a percent order discount is taken off the new items total', function (): void {
    app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([], DiscountType::Percent, '10', 'عميل دائم'), $this->owner->id);

    ($this->edit)([($this->a)()]);

    expect(($this->totals)())->toBe(['items_total' => '540.00', 'discount_amount' => '54.00', 'order_total' => '486.00']);
});

test('D85 an EGP order discount keeps its amount', function (): void {
    app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([], DiscountType::Amount, '40', 'عميل دائم'), $this->owner->id);

    ($this->edit)([($this->a)()]);

    expect(($this->totals)())->toBe(['items_total' => '540.00', 'discount_amount' => '40.00', 'order_total' => '500.00']);
});

test('D85 an EGP order discount larger than the new items total refuses the edit', function (): void {
    app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([], DiscountType::Amount, '600', 'عميل دائم'), $this->owner->id);

    expect(fn (): EditedOrder => ($this->edit)([($this->a)()]))->toThrow(DiscountTooLarge::class);

    expect(($this->totals)())->toBe(['items_total' => '1390.00', 'discount_amount' => '600.00', 'order_total' => '790.00'])
        ->and(($this->reserved)($this->skuB))->toBe(200);
});

test('BR-PR06 a stock shortfall is refused like checkout and nothing changes', function (): void {
    $short = $this->orderableSku(['code' => 'D-1'], onHand: 40);

    expect(fn (): EditedOrder => ($this->edit)([($this->a)(8), ($this->b)(), [null, $short->id, 5]]))->toThrow(function (CheckoutFailed $e) use ($short): void {
        expect([$e->status, $e->errorCode, $e->items])->toBe([422, 'checkout_failed', [['sku_id' => $short->id, 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 2]]]);
    });

    ($this->expectUnchanged)();
});

test('BR-O12 what the order already holds counts as available to it', function (): void {
    $tight = $this->orderableSku(['code' => 'E-1'], onHand: 240);
    $order = $this->placeOrder($this->customer, [$tight->id => 10]);
    $line = OrderItem::query()->where('order_id', $order->id)->sole();

    app(EditOrderLines::class)->handle($order, new OrderEditData([new OrderEditLineData($line->id, $tight->id, 12)]), $this->owner->id);

    expect(SkuInventory::query()->findOrFail($tight->id)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 240, 'reserved' => 240]);
});

test('BR-O12 an increase beyond the stock and the order\'s own reservation is refused', function (): void {
    $tight = $this->orderableSku(['code' => 'E-1'], onHand: 240);
    $order = $this->placeOrder($this->customer, [$tight->id => 10]);
    $line = OrderItem::query()->where('order_id', $order->id)->sole();

    expect(fn (): EditedOrder => app(EditOrderLines::class)->handle($order, new OrderEditData([new OrderEditLineData($line->id, $tight->id, 13)]), $this->owner->id))
        ->toThrow(function (CheckoutFailed $e) use ($tight): void {
            expect($e->items)->toBe([['sku_id' => $tight->id, 'reason' => 'insufficient_stock', 'requested' => 13, 'available' => 12]]);
        });

    expect(SkuInventory::query()->findOrFail($tight->id)->reserved)->toBe(200);
});

test('BR-MOQ01 a quantity below the MOQ is refused', function (): void {
    expect(fn (): EditedOrder => ($this->edit)([($this->a)(3), ($this->b)()]))->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->skuA->id, 'reason' => 'below_moq', 'moq' => 5]]);
    });

    ($this->expectUnchanged)();
});

test('BR-O12 a confirmed order is edited too', function (): void {
    $this->order->forceFill(['status' => OrderStatus::Confirmed])->save();

    expect(($this->edit)([($this->a)()])->order->order_total)->toBe('540.00');
});

test('BR-O12 from preparing on the lines are locked', function (OrderStatus $status): void {
    $this->order->forceFill(['status' => $status])->save();

    expect(fn (): EditedOrder => ($this->edit)([($this->a)()]))->toThrow(function (OrderEditRefused $e) use ($status): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'order_locked', ['status' => $status->value]]);
    });

    ($this->expectUnchanged)();
})->with([
    'preparing' => [OrderStatus::Preparing],
    'out for delivery' => [OrderStatus::OutForDelivery],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('BR-C03 an edit that leaves the order below what was collected is refused', function (): void {
    $this->order->forceFill(['collected_amount' => '1000.00', 'collection_status' => CollectionStatus::PartiallyCollected])->save();

    expect(fn (): EditedOrder => ($this->edit)([($this->a)()]))->toThrow(function (OrderEditRefused $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'below_collected', ['collected_amount' => '1000.00', 'order_total' => '540.00']]);
    });

    ($this->expectUnchanged)();
});

test('BR-C03 the collection status is derived again from the new total', function (): void {
    $this->order->forceFill(['collected_amount' => '540.00', 'collection_status' => CollectionStatus::PartiallyCollected])->save();

    expect(($this->edit)([($this->a)()])->order->refresh()->collection_status)->toBe(CollectionStatus::Collected);
});

test('BR-MOV02 going below the minimum order value is allowed and reported', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1000.00']);

    $edited = ($this->edit)([($this->a)()]);

    expect($edited->belowMov)->toBe(['required' => '1000.00', 'current' => '540.00'])
        ->and($edited->order->refresh()->order_total)->toBe('540.00');
});

test('BR-MOV02 an order still above the minimum reports nothing', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '500.00']);

    expect(($this->edit)([($this->a)()])->belowMov)->toBeNull();
});

test('BR-C05 a higher total past the credit limit is refused, the order\'s old total counted once', function (): void {
    $this->customer->forceFill(['credit_limit' => '1500.00'])->save();

    expect(fn (): EditedOrder => ($this->edit)([($this->a)(8), ($this->b)()]))->toThrow(function (CheckoutFailed $e): void {
        expect($e->details)->toBe(['credit' => ['limit' => '1500.00', 'exposure' => '0.00', 'order_total' => '1570.00']]);
    });

    ($this->expectUnchanged)();
});

test('BR-C05 a higher total within the credit limit passes', function (): void {
    $this->customer->forceFill(['credit_limit' => '1570.00'])->save();

    expect(($this->edit)([($this->a)(8), ($this->b)()])->order->order_total)->toBe('1570.00');
});

test('BR-C05 a lower total is never refused by the credit limit', function (): void {
    $this->customer->forceFill(['credit_limit' => '100.00'])->save();

    expect(($this->edit)([($this->a)()])->order->order_total)->toBe('540.00');
});

test('BR-O12 a line id that is not a live line of the order is a validation error on its field', function (): void {
    $other = OrderItem::factory()->create();

    expect(fn (): EditedOrder => ($this->edit)([($this->a)(), [$other->id, $this->skuB->id, 10]]))->toThrow(function (OrderEditRefused $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'validation_failed', ['lines.1.order_item_id' => ['البند غير موجود في هذا الطلب']]]);
    });

    ($this->expectUnchanged)();
});

test('BR-O12 a removed line cannot be edited again', function (): void {
    ($this->edit)([($this->a)()]);

    expect(fn (): EditedOrder => ($this->edit)([($this->a)(), ($this->b)(12)]))->toThrow(OrderEditRefused::class);
});

test('BR-O12 an existing line cannot change its SKU', function (): void {
    expect(fn (): EditedOrder => ($this->edit)([[$this->lineA->id, $this->skuC->id, 6], ($this->b)()]))->toThrow(function (OrderEditRefused $e): void {
        expect([$e->status, $e->details])->toBe([422, ['lines.0.sku_id' => ['لا يمكن تغيير صنف بند موجود، احذفه وأضف الصنف الآخر']]]);
    });

    ($this->expectUnchanged)();
});

test('BR-O12 a new line of a SKU the store does not have is a validation error on its field', function (): void {
    $foreign = $this->orderableSku(['code' => 'X-1'], tenant: $this->otherTenant());

    expect(fn (): EditedOrder => ($this->edit)([($this->a)(), ($this->b)(), [null, $foreign->id, 5]]))->toThrow(function (CheckoutFailed $e): void {
        expect([$e->status, $e->details])->toBe([422, ['lines.2.sku_id' => ['المنتج غير موجود']]]);
    });

    ($this->expectUnchanged)();
});

test('BR-O12 sending the lines the order already has writes nothing', function (): void {
    ($this->edit)([($this->a)(), ($this->b)()]);

    ($this->expectUnchanged)();
});

test('BR-O12 every line change is kept with its quantities, prices and the user', function (): void {
    ($this->edit)([($this->a)(12), [null, $this->skuC->id, 5]]);

    $added = OrderItem::query()->where('order_id', $this->order->id)->where('sku_id', $this->skuC->id)->sole();

    expect(OrderItemChange::query()->orderBy('id')->get()->map->only(['order_item_id', 'change', 'old_quantity', 'new_quantity', 'unit_price_before', 'unit_price_after', 'actor_id'])->all())->toBe([
        ['order_item_id' => $this->lineB->id, 'change' => OrderItemChangeType::Removed, 'old_quantity' => 10, 'new_quantity' => null, 'unit_price_before' => '85.00', 'unit_price_after' => null, 'actor_id' => $this->owner->id],
        ['order_item_id' => $this->lineA->id, 'change' => OrderItemChangeType::Quantity, 'old_quantity' => 6, 'new_quantity' => 12, 'unit_price_before' => '90.00', 'unit_price_after' => '85.00', 'actor_id' => $this->owner->id],
        ['order_item_id' => $added->id, 'change' => OrderItemChangeType::Added, 'old_quantity' => null, 'new_quantity' => 5, 'unit_price_before' => null, 'unit_price_after' => '90.00', 'actor_id' => $this->owner->id],
    ]);
});

test('BR-L the edit is audited with the totals that changed and the line changes', function (): void {
    ($this->edit)([($this->a)(12)], 'العميل اتصل');

    $log = AuditLog::query()->where('action', 'order.lines_changed')->sole();

    expect($log->only(['entity_type', 'entity_id']))->toEqual(['entity_type' => 'orders', 'entity_id' => $this->order->id])
        ->and($log->old_values)->toEqual(['items_total' => '1390.00', 'order_total' => '1390.00'])
        ->and($log->new_values)->toEqual([
            'items_total' => '1020.00', 'order_total' => '1020.00',
            'changes' => [
                ['order_item_id' => $this->lineB->id, 'change' => 'removed', 'old_quantity' => 10, 'unit_price_before' => '85.00'],
                ['order_item_id' => $this->lineA->id, 'change' => 'quantity', 'old_quantity' => 6, 'new_quantity' => 12, 'unit_price_before' => '90.00', 'unit_price_after' => '85.00'],
            ],
            'note' => 'العميل اتصل',
        ]);
});

test('BR-O04 the history gets the note «تعديل الطلب» by the user, without a status change', function (?string $note, string $expected): void {
    ($this->edit)([($this->a)()], $note);

    expect(OrderStatusHistory::query()->where('order_id', $this->order->id)->latest('id')->firstOrFail()->only(['from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
        'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Pending, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id, 'note' => $expected,
    ]);
})->with([
    'without a note' => [null, 'تعديل الطلب'],
    'with the merchant\'s note' => ['نفد الكتاب', 'تعديل الطلب: نفد الكتاب'],
]);

test('D87 the customer is told the new total by default', function (): void {
    ($this->edit)([($this->a)()]);

    expect(OutboundMessage::query()->where('purpose', MessagePurpose::OrderStatus)->sole()->body)
        ->toBe("أهلًا محمود، تم تعديل أصناف طلبك رقم {$this->order->number} من {$this->tenant->name}، والإجمالي الآن 540.00. التفاصيل في صفحة الطلب.");
});

test('D87 nothing is sent when the store does not notify edits', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['confirmed'])]);

    ($this->edit)([($this->a)()]);

    expect(OutboundMessage::query()->count())->toBe(0);
});

test('BR-O12 a failed edit sends nothing', function (): void {
    expect(fn (): EditedOrder => ($this->edit)([($this->a)(3)]))->toThrow(CheckoutFailed::class);

    expect(OutboundMessage::query()->count())->toBe(0);
});
