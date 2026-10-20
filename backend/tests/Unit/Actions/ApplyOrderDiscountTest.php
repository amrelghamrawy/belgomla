<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Actions\ApplyOrderDiscount;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Data\LineDiscountData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\DiscountRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Exceptions\DiscountTooLarge;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

/*
| One pending order of two lines: 6 cartons of A at 90.00 (540.00) and 10 cartons of B at the 10+ tier price 85.00
| (850.00), items and order total 1390.00.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'محمود']);
    $this->order = $this->placeOrder($this->customer, [$this->orderableSku(['code' => 'A-1'])->id => 6, $this->orderableSku(['code' => 'B-1'], name: 'كتاب ب')->id => 10]);
    app(TenantContext::class)->set($this->tenant);
    [$this->lineA, $this->lineB] = OrderItem::query()->where('order_id', $this->order->id)->orderBy('id')->get()->all();
    AuditLog::query()->delete();
    OutboundMessage::query()->delete();

    $this->apply = fn (array $lines = [], ?array $order = null, bool $notify = true): Order => app(ApplyOrderDiscount::class)->handle(
        $this->order,
        new DiscountData(
            array_map(fn (array $line): LineDiscountData => new LineDiscountData($line[0]->id, $line[1], $line[2]), $lines),
            $order[0] ?? null,
            $order[1] ?? null,
            $order[2] ?? null,
        ),
        $this->owner->id,
        $notify,
    );
    $this->asSales = function (): void {
        $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Sales]));
    };
    $this->cap = fn (?string $percent) => TenantSetting::query()->whereKey($this->tenant->id)->update(['max_discount_percent_sales' => $percent]);
    $this->expectUnchanged = function (): void {
        expect(Order::query()->findOrFail($this->order->id)->only(['items_total', 'discount_amount', 'order_total']))->toBe(['items_total' => '1390.00', 'discount_amount' => '0.00', 'order_total' => '1390.00'])
            ->and(OrderItem::query()->whereNotNull('manual_discount_type')->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    };
});

test('BR-PR08 BR-O08 a line discount and an order discount rewrite the lines and the totals', function (): void {
    $order = ($this->apply)([[$this->lineA, DiscountType::Percent, '10'], [$this->lineB, DiscountType::Amount, '5']], [DiscountType::Amount, '50', 'عميل دائم']);

    expect($this->lineA->refresh()->only(['list_unit_price', 'manual_discount_type', 'manual_discount_value', 'unit_price', 'line_total']))->toBe([
        'list_unit_price' => '90.00', 'manual_discount_type' => DiscountType::Percent, 'manual_discount_value' => '10.00', 'unit_price' => '81.00', 'line_total' => '486.00',
    ])
        ->and($this->lineB->refresh()->only(['unit_price', 'line_total']))->toBe(['unit_price' => '80.00', 'line_total' => '800.00'])
        ->and($order->refresh()->only(['items_total', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason', 'order_total']))->toBe([
            'items_total' => '1286.00', 'discount_type' => DiscountType::Amount, 'discount_value' => '50.00', 'discount_amount' => '50.00', 'discount_reason' => 'عميل دائم', 'order_total' => '1236.00',
        ]);
});

test('D85 a percent order discount is taken off the items total after the line discounts', function (): void {
    $order = ($this->apply)([[$this->lineA, DiscountType::Percent, '10']], [DiscountType::Percent, '10', 'كمية كبيرة']);

    expect($order->refresh()->only(['items_total', 'discount_amount', 'order_total']))->toBe(['items_total' => '1336.00', 'discount_amount' => '133.60', 'order_total' => '1202.40']);
});

test('D85 removing a discount restores the list price and the totals', function (): void {
    ($this->apply)([[$this->lineA, DiscountType::Percent, '10']], [DiscountType::Amount, '50', 'عميل دائم']);

    $order = ($this->apply)();

    expect($this->lineA->refresh()->only(['manual_discount_type', 'manual_discount_value', 'unit_price', 'line_total']))->toBe([
        'manual_discount_type' => null, 'manual_discount_value' => null, 'unit_price' => '90.00', 'line_total' => '540.00',
    ])
        ->and($order->refresh()->only(['items_total', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason', 'order_total']))->toBe([
            'items_total' => '1390.00', 'discount_type' => null, 'discount_value' => null, 'discount_amount' => '0.00', 'discount_reason' => null, 'order_total' => '1390.00',
        ]);
});

test('D85 a line sent without a type has no discount', function (): void {
    ($this->apply)([[$this->lineA, DiscountType::Percent, '10']]);

    ($this->apply)([[$this->lineA, null, null]]);

    expect($this->lineA->refresh()->unit_price)->toBe('90.00');
});

test('D85 a confirmed order still takes a discount', function (): void {
    $this->order->forceFill(['status' => OrderStatus::Confirmed])->save();

    expect(($this->apply)([[$this->lineA, DiscountType::Amount, '10']])->order_total)->toBe('1330.00');
});

test('D85 from preparing on the prices are locked', function (OrderStatus $status): void {
    $this->order->forceFill(['status' => $status])->save();

    expect(fn (): Order => ($this->apply)([[$this->lineA, DiscountType::Percent, '10']]))->toThrow(function (DiscountRefused $e) use ($status): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'order_locked', ['status' => $status->value]]);
    });

    ($this->expectUnchanged)();
})->with([
    'preparing' => [OrderStatus::Preparing],
    'out for delivery' => [OrderStatus::OutForDelivery],
    'delivered' => [OrderStatus::Delivered],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('BR-C03 a discount that would leave the order below what was collected is refused', function (): void {
    $this->order->forceFill(['collected_amount' => '1300.00', 'collection_status' => CollectionStatus::PartiallyCollected])->save();

    expect(fn (): Order => ($this->apply)(order: [DiscountType::Amount, '100', 'عميل دائم']))->toThrow(function (DiscountRefused $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'below_collected', ['collected_amount' => '1300.00', 'order_total' => '1290.00']]);
    });

    ($this->expectUnchanged)();
});

test('BR-C03 the collection status is derived again from the new total', function (): void {
    $this->order->forceFill(['collected_amount' => '1290.00', 'collection_status' => CollectionStatus::PartiallyCollected])->save();

    $order = ($this->apply)(order: [DiscountType::Amount, '100', 'عميل دائم']);

    expect($order->refresh()->collection_status)->toBe(CollectionStatus::Collected);
});

test('D85 a line of another order is a validation error on its field', function (): void {
    $other = OrderItem::factory()->create();

    expect(fn (): Order => app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([new LineDiscountData($this->lineA->id, DiscountType::Amount, '5'), new LineDiscountData($other->id, DiscountType::Amount, '5')]), $this->owner->id))
        ->toThrow(function (DiscountRefused $e): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'validation_failed', ['items.1.order_item_id' => ['البند غير موجود في هذا الطلب']]]);
        });

    ($this->expectUnchanged)();
});

test('D85 a discount too large on one line writes nothing', function (): void {
    expect(fn (): Order => ($this->apply)([[$this->lineA, DiscountType::Percent, '10'], [$this->lineB, DiscountType::Amount, '85']]))
        ->toThrow(DiscountTooLarge::class);

    ($this->expectUnchanged)();
});

test('D85 an order discount too large writes nothing', function (): void {
    expect(fn (): Order => ($this->apply)([[$this->lineA, DiscountType::Percent, '10']], [DiscountType::Amount, '1336', 'هدية']))
        ->toThrow(DiscountTooLarge::class);

    ($this->expectUnchanged)();
});

test('BR-L the change is audited with the old and new values of the lines that changed and of the order', function (): void {
    ($this->apply)([[$this->lineA, DiscountType::Percent, '10']], [DiscountType::Amount, '50', 'عميل دائم']);

    $log = AuditLog::query()->where('action', 'order.discount_changed')->sole();

    expect($log->only(['entity_type', 'entity_id']))->toEqual(['entity_type' => 'orders', 'entity_id' => $this->order->id])
        ->and($log->old_values)->toEqual([
            'items' => [['order_item_id' => $this->lineA->id, 'list_unit_price' => '90.00', 'type' => null, 'value' => null, 'unit_price' => '90.00', 'line_total' => '540.00']],
            'items_total' => '1390.00',
            'discount' => ['type' => null, 'value' => null, 'amount' => '0.00', 'reason' => null],
            'order_total' => '1390.00',
        ])
        ->and($log->new_values)->toEqual([
            'items' => [['order_item_id' => $this->lineA->id, 'list_unit_price' => '90.00', 'type' => 'percent', 'value' => '10.00', 'unit_price' => '81.00', 'line_total' => '486.00']],
            'items_total' => '1336.00',
            'discount' => ['type' => 'amount', 'value' => '50.00', 'amount' => '50.00', 'reason' => 'عميل دائم'],
            'order_total' => '1286.00',
        ]);
});

test('BR-O04 the history gets the note «خصم» by the user, without a status change', function (): void {
    ($this->apply)([[$this->lineA, DiscountType::Percent, '10']]);

    expect(OrderStatusHistory::query()->where('order_id', $this->order->id)->latest('id')->firstOrFail()->only(['from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
        'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Pending, 'actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id, 'note' => 'خصم',
    ]);
});

test('D85 sending the discounts the order already has writes nothing', function (): void {
    ($this->apply)([[$this->lineA, DiscountType::Percent, '10']]);

    ($this->apply)([[$this->lineA, DiscountType::Percent, '10.00']]);

    expect(AuditLog::query()->where('action', 'order.discount_changed')->count())->toBe(1)
        ->and(OrderStatusHistory::query()->where('order_id', $this->order->id)->where('note', 'خصم')->count())->toBe(1);
});

test('D85 a sales user cannot discount while the store sets no cap', function (): void {
    ($this->asSales)();

    expect(fn (): Order => ($this->apply)([[$this->lineA, DiscountType::Percent, '5']]))->toThrow(function (DiscountRefused $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([403, 'discount_over_limit', ['max_percent' => null]]);
    });

    ($this->expectUnchanged)();
});

test('D85 a sales user discounts a line up to the store cap', function (DiscountType $type, string $value): void {
    ($this->cap)('10');
    ($this->asSales)();

    expect(($this->apply)([[$this->lineA, $type, $value]])->items_total)->not->toBe('1390.00');
})->with(['exactly the cap in %' => [DiscountType::Percent, '10'], 'an amount within the cap (9 of 90)' => [DiscountType::Amount, '9']]);

test('D85 a sales user above the cap is refused', function (DiscountType $type, string $value): void {
    ($this->cap)('10');
    ($this->asSales)();

    expect(fn (): Order => ($this->apply)([[$this->lineA, $type, $value]]))->toThrow(function (DiscountRefused $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([403, 'discount_over_limit', ['max_percent' => '10.00']]);
    });

    ($this->expectUnchanged)();
})->with(['a percent' => [DiscountType::Percent, '10.01'], 'an amount (9.01 of 90)' => [DiscountType::Amount, '9.01']]);

test('D85 a sales user cannot give an order discount', function (): void {
    ($this->cap)('10');
    ($this->asSales)();

    expect(fn (): Order => ($this->apply)(order: [DiscountType::Amount, '5', 'عميل دائم']))->toThrow(DiscountRefused::class);

    ($this->expectUnchanged)();
});

test('D85 a sales user keeps a manager\'s discount and may remove one', function (): void {
    ($this->apply)([[$this->lineA, DiscountType::Percent, '30'], [$this->lineB, DiscountType::Percent, '30']], [DiscountType::Amount, '50', 'عميل دائم']);
    ($this->asSales)();

    $order = ($this->apply)([[$this->lineA, DiscountType::Percent, '30']], [DiscountType::Amount, '50', 'عميل دائم']);

    expect($this->lineB->refresh()->manual_discount_type)->toBeNull()
        ->and($this->lineA->refresh()->unit_price)->toBe('63.00')
        ->and($order->discount_amount)->toBe('50.00');
});

test('BR-N03 the customer is told the new total when the store notifies discounts', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['discount'])]);

    ($this->apply)(order: [DiscountType::Amount, '90', 'عميل دائم']);

    expect(OutboundMessage::query()->where('purpose', MessagePurpose::OrderStatus)->sole()->body)->toBe("أهلًا محمود، تم تطبيق خصم على طلبك رقم {$this->order->number} من {$this->tenant->name}، والإجمالي الآن 1300.00.");
});

test('BR-N03 nothing is sent while the store does not notify discounts (the default)', function (): void {
    ($this->apply)(order: [DiscountType::Amount, '90', 'عميل دائم']);

    expect(OutboundMessage::query()->count())->toBe(0);
});

test('D85 a discount given on the order form is not messaged on its own', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['discount'])]);

    ($this->apply)(order: [DiscountType::Amount, '90', 'عميل دائم'], notify: false);

    expect(OutboundMessage::query()->count())->toBe(0);
});
