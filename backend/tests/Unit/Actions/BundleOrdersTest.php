<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\ApplyOrderDiscount;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\EditOrderLines;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Data\LineDiscountData;
use App\Modules\Orders\Data\OrderEditData;
use App\Modules\Orders\Data\OrderEditLineData;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\DiscountRefused;
use App\Modules\Orders\Exceptions\OrderEditRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Services\ReturnableQuantities;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Tenancy\TenantContext;

/*
| BR-P15, V2 Task 15, D93: an order of a bundle, end to end. The bundle: one carton (20 pieces) of عربي and two cartons
| of رياضيات (each 90.00 a carton) at a fixed 100.00, so the components weigh 90 : 180 and split each bundle 33.33 :
| 66.67. 400 pieces of each are in stock.
*/

beforeEach(function (): void {
    $this->customer = $this->makeCustomer();
    $this->owner = $this->makeOwner();
    $this->arabic = $this->orderableSku(['code' => 'AR-1'], name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1'], name: 'رياضيات');
    $this->bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 2], value: '100.00');
    $this->bundleSkuId = $this->bundleSku($this->bundle)->id;
    app(TenantContext::class)->set($this->tenant);

    $this->stock = fn (int $skuId): array => SkuInventory::query()->findOrFail($skuId)->only(['on_hand', 'reserved']);
    $this->lines = fn (Order $order) => OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
    $this->bundleLine = fn (Order $order): OrderItem => OrderItem::query()->where('order_id', $order->id)->where('is_bundle', true)->sole();
});

describe('checkout', function (): void {
    test('BR-O05 D93 a bundle is snapshotted as its line and one child line per component, the price split by their own prices', function (): void {
        $order = $this->placeOrder($this->customer, [$this->bundleSkuId => 3]);

        $lines = ($this->lines)($order)->map(fn (OrderItem $item): array => $item->only(['sku_code', 'is_bundle', 'reserved', 'quantity', 'quantity_base', 'unit_price', 'line_total']) + ['child' => $item->parent_item_id !== null])->all();

        expect($lines)->toBe([
            ['sku_code' => 'SET-1', 'is_bundle' => true, 'reserved' => false, 'quantity' => 3, 'quantity_base' => 3, 'unit_price' => '100.00', 'line_total' => '300.00', 'child' => false],
            ['sku_code' => 'AR-1', 'is_bundle' => false, 'reserved' => true, 'quantity' => 3, 'quantity_base' => 60, 'unit_price' => '33.33', 'line_total' => '99.99', 'child' => true],
            ['sku_code' => 'MA-1', 'is_bundle' => false, 'reserved' => true, 'quantity' => 6, 'quantity_base' => 120, 'unit_price' => '33.34', 'line_total' => '200.01', 'child' => true],
        ]);
    });

    test('D93 the children\'s totals add up to the bundle line exactly', function (): void {
        $order = $this->placeOrder($this->customer, [$this->bundleSkuId => 7]);

        $children = ($this->lines)($order)->whereNotNull('parent_item_id');

        expect(bcadd($children->first()->line_total, $children->last()->line_total, 2))->toBe(($this->bundleLine)($order)->line_total);
    });

    test('BR-O08 D93 the items total counts the bundle once', function (): void {
        $order = $this->placeOrder($this->customer, [$this->bundleSkuId => 3, $this->arabic->id => 5]);

        expect($order->only(['items_total', 'order_total']))->toBe(['items_total' => '750.00', 'order_total' => '750.00']); // 300 + 5 × 90
    });

    test('BR-INV03 D93 the components are reserved, the bundle holds nothing', function (): void {
        $this->placeOrder($this->customer, [$this->bundleSkuId => 3, $this->maths->id => 5]);

        expect(($this->stock)($this->arabic->id))->toBe(['on_hand' => 400, 'reserved' => 60])
            ->and(($this->stock)($this->maths->id))->toBe(['on_hand' => 400, 'reserved' => 220])
            ->and(SkuInventory::query()->whereKey($this->bundleSkuId)->exists())->toBeFalse();
    });

    test('BR-INV04 D93 cancelling gives the components back', function (): void {
        $order = $this->placeOrder($this->customer, [$this->bundleSkuId => 3]);

        app(CancelOrder::class)->handle($order, new CancelData('طلب العميل'), CancelledBy::TenantUser, $this->owner->id);

        expect(($this->stock)($this->arabic->id)['reserved'])->toBe(0)
            ->and(($this->stock)($this->maths->id)['reserved'])->toBe(0);
    });
});

describe('delivery', function (): void {
    beforeEach(function (): void {
        $this->order = $this->placeOrder($this->customer, [$this->bundleSkuId => 3]);
        $this->deliver = fn (?array $quantities = null): Order => app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Delivered, deliveredQuantities: $quantities), $this->owner->id);
    });

    test('BR-INV05 D93 delivery deducts the components with sale movements', function (): void {
        ($this->deliver)();

        expect(($this->stock)($this->arabic->id))->toBe(['on_hand' => 340, 'reserved' => 0])
            ->and(($this->stock)($this->maths->id))->toBe(['on_hand' => 280, 'reserved' => 0])
            ->and(InventoryMovement::query()->where('type', 'sale')->orderBy('sku_id')->pluck('quantity', 'sku_id')->all())->toBe([$this->arabic->id => -60, $this->maths->id => -120]);
    });

    test('BR-INV09 D96 f delivery freezes the own cost of each component line; the bundle line has none', function (): void {
        SkuInventory::query()->whereKey($this->arabic->id)->update(['avg_cost' => '2.0000']);

        ($this->deliver)();

        $costs = ($this->lines)($this->order)->map(fn (OrderItem $item): array => [$item->sku_code, $item->unit_cost])->all();

        expect($costs)->toBe([['SET-1', null], ['AR-1', '2.0000'], ['MA-1', null]]);
    });

    test('BR-O13 D93 a partial delivery is given in bundles; the components follow', function (): void {
        ($this->deliver)([($this->bundleLine)($this->order)->id => 2]);

        $lines = ($this->lines)($this->order)->map(fn (OrderItem $item): array => [$item->sku_code, $item->delivered_quantity, $item->line_total])->all();

        expect($lines)->toBe([['SET-1', 2, '200.00'], ['AR-1', 2, '66.66'], ['MA-1', 4, '133.34']])
            ->and(($this->stock)($this->arabic->id))->toBe(['on_hand' => 360, 'reserved' => 0])
            ->and(($this->stock)($this->maths->id))->toBe(['on_hand' => 320, 'reserved' => 0])
            ->and($this->order->refresh()->only(['items_total', 'order_total']))->toBe(['items_total' => '200.00', 'order_total' => '200.00']);
    });

    test('BR-O13 D93 the bundle line records the short delivery', function (): void {
        ($this->deliver)([($this->bundleLine)($this->order)->id => 1]);

        expect(OrderItemChange::query()->sole()->only(['order_item_id', 'old_quantity', 'new_quantity']))->toBe(['order_item_id' => ($this->bundleLine)($this->order)->id, 'old_quantity' => 3, 'new_quantity' => 1]);
    });

    test('D93 a component line cannot be delivered apart from its bundle', function (): void {
        $child = ($this->lines)($this->order)->whereNotNull('parent_item_id')->first();

        expect(fn () => ($this->deliver)([$child->id => 1]))->toThrow(OrderEditRefused::class)
            ->and(($this->stock)($this->arabic->id)['reserved'])->toBe(60);
    });

    test('BR-R02 D93 returns are made of component lines; the bundle line is not returnable', function (): void {
        ($this->deliver)();

        $returnable = app(ReturnableQuantities::class)->for($this->order->refresh());

        expect(array_keys($returnable))->not->toContain(($this->bundleLine)($this->order)->id)
            ->and(array_values($returnable))->toBe([3, 6]);
    });

    test('BR-R03 D74 D93 one book of a bundle comes back at its allocated price', function (): void {
        ($this->deliver)();
        $maths = ($this->lines)($this->order)->firstWhere('sku_code', 'MA-1');

        $return = app(RecordReturn::class)->handle($this->order, new ReturnData('كتاب تالف', [new ReturnLineData($maths->id, 1)]), $this->owner->id);

        expect($return->total)->toBe('33.34')
            ->and(($this->stock)($this->maths->id)['on_hand'])->toBe(300)
            ->and($this->order->refresh()->returned_total)->toBe('33.34');
    });

    test('BR-R04 D93 returning every component brings the order back to exactly zero', function (): void {
        ($this->deliver)();
        $children = ($this->lines)($this->order)->whereNotNull('parent_item_id');

        app(RecordReturn::class)->handle($this->order, new ReturnData('مرتجع كامل', $children->map(fn (OrderItem $item): ReturnLineData => new ReturnLineData($item->id, $item->quantity))->values()->all()), $this->owner->id);

        expect($this->order->refresh()->netTotal())->toBe('0.00');
    });
});

describe('editing and discounts', function (): void {
    beforeEach(function (): void {
        $this->order = $this->placeOrder($this->customer, [$this->bundleSkuId => 3, $this->arabic->id => 5]);
        $this->edit = fn (array $lines): mixed => app(EditOrderLines::class)->handle($this->order, new OrderEditData($lines), $this->owner->id);
        $this->loose = fn (): OrderItem => ($this->lines)($this->order)->first(fn (OrderItem $item): bool => $item->sku_code === 'AR-1' && $item->parent_item_id === null);
    });

    test('BR-O12 D93 a bundle line\'s quantity changes with its components and their reservation', function (): void {
        $bundle = ($this->bundleLine)($this->order);

        ($this->edit)([new OrderEditLineData($bundle->id, $bundle->sku_unit_id ?? 0, 5), new OrderEditLineData(($this->loose)()->id, ($this->loose)()->sku_unit_id ?? 0, 5)]);

        $children = OrderItem::query()->where('parent_item_id', $bundle->id)->orderBy('id')->get();

        expect($children->map(fn (OrderItem $item): array => [$item->quantity, $item->line_total])->all())->toBe([[5, '166.65'], [10, '333.35']])
            ->and(($this->stock)($this->arabic->id)['reserved'])->toBe(200) // 5 bundles + 5 loose cartons
            ->and(($this->stock)($this->maths->id)['reserved'])->toBe(200)
            ->and($this->order->refresh()->items_total)->toBe('950.00');
    });

    test('BR-O12 D93 removing a bundle line releases and removes its components', function (): void {
        ($this->edit)([new OrderEditLineData(($this->loose)()->id, ($this->loose)()->sku_unit_id ?? 0, 5)]);

        expect(OrderItem::query()->where('order_id', $this->order->id)->whereNull('removed_at')->count())->toBe(1)
            ->and(($this->stock)($this->maths->id)['reserved'])->toBe(0)
            ->and($this->order->refresh()->items_total)->toBe('450.00');
    });

    test('BR-O12 D93 a bundle added to an order reserves its components', function (): void {
        $bundle = ($this->bundleLine)($this->order);

        ($this->edit)([
            new OrderEditLineData($bundle->id, $bundle->sku_unit_id ?? 0, 3),
            new OrderEditLineData(($this->loose)()->id, ($this->loose)()->sku_unit_id ?? 0, 5),
            new OrderEditLineData(null, $this->defaultUnitId($this->gradeSet([$this->maths->id => 1], value: '80.00', code: 'SET-2')->skus()->sole()), 2),
        ]);

        expect(($this->stock)($this->maths->id)['reserved'])->toBe(160) // 3 × 40 + 2 × 20
            ->and($this->order->refresh()->items_total)->toBe('910.00');
    });

    test('D93 a component line is not a line of its own to edit', function (): void {
        $child = ($this->lines)($this->order)->whereNotNull('parent_item_id')->first();

        ($this->edit)([new OrderEditLineData($child->id, $child->sku_unit_id ?? 0, 1)]);
    })->throws(OrderEditRefused::class);

    test('BR-PR08 D93 a discount on the bundle line is split over its components again', function (): void {
        $bundle = ($this->bundleLine)($this->order);

        app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([new LineDiscountData($bundle->id, DiscountType::Amount, '10.00')]), $this->owner->id, notifyCustomer: false);

        $children = OrderItem::query()->where('parent_item_id', $bundle->id)->orderBy('id')->get();

        expect($bundle->refresh()->line_total)->toBe('270.00')
            ->and($children->map(fn (OrderItem $item): array => [$item->unit_price, $item->line_total])->all())->toBe([['30.00', '90.00'], ['30.00', '180.00']])
            ->and($this->order->refresh()->items_total)->toBe('720.00');
    });

    test('D93 a component line takes no discount of its own', function (): void {
        $child = ($this->lines)($this->order)->whereNotNull('parent_item_id')->first();

        app(ApplyOrderDiscount::class)->handle($this->order, new DiscountData([new LineDiscountData($child->id, DiscountType::Amount, '1.00')]), $this->owner->id, notifyCustomer: false);
    })->throws(DiscountRefused::class);
});
