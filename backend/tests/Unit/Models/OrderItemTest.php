<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $item = OrderItem::factory()->create()->fresh();

    expect(array_keys($item?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'order_id', 'sku_id', 'product_id', 'product_name', 'sku_code', 'sku_name', 'unit_name',
        'conversion_factor', 'quantity', 'quantity_base', 'cover_price', 'unit_price', 'discount_percent',
        'tier_min_quantity', 'line_total', 'created_at', 'updated_at', 'list_unit_price', 'manual_discount_type', 'manual_discount_value',
        'delivered_quantity', 'removed_at', 'reserved', 'converted_quantity', 'price_source', 'promotion_id', 'sku_unit_id', 'parent_item_id', 'is_bundle',
    ]);
});

test('casts: integers and money strings', function (): void {
    $item = OrderItem::factory()->create(['quantity' => '3', 'unit_price' => 85, 'cover_price' => '120', 'discount_percent' => 29.2, 'tier_min_quantity' => '10'])->fresh();

    expect($item?->only(['quantity', 'quantity_base', 'unit_price', 'cover_price', 'discount_percent', 'tier_min_quantity', 'line_total']))->toBe([
        'quantity' => 3, 'quantity_base' => 3, 'unit_price' => '85.00', 'cover_price' => '120.00', 'discount_percent' => '29.20', 'tier_min_quantity' => 10, 'line_total' => '255.00',
    ]);
});

test('BR-U03 the database keeps quantity_base = quantity × conversion factor and quantity ≥ 1', function (array $attributes): void {
    expect(fn () => OrderItem::factory()->create($attributes))->toThrow(QueryException::class);
})->with(['wrong base' => [['quantity' => 2, 'quantity_base' => 3]], 'zero quantity' => [['quantity' => 0]]]);

test('a line belongs to its order and its sku', function (): void {
    $sku = Sku::factory()->create();
    $order = Order::factory()->create();

    $item = OrderItem::factory()->for($order)->create(['sku_id' => $sku->id]);

    expect($item->order->is($order))->toBeTrue()
        ->and($item->sku->is($sku))->toBeTrue()
        ->and($item->product_id)->toBe($sku->product_id);
});

test('D85 a line keeps its list price and its manual discount, cast to an enum and money', function (): void {
    $item = OrderItem::factory()->create(['quantity' => 2, 'list_unit_price' => '90', 'manual_discount_type' => 'percent', 'manual_discount_value' => 10, 'unit_price' => '81'])->fresh();

    expect($item?->only(['list_unit_price', 'manual_discount_type', 'manual_discount_value', 'unit_price', 'line_total']))->toBe([
        'list_unit_price' => '90.00', 'manual_discount_type' => App\Modules\Pricing\Enums\DiscountType::Percent, 'manual_discount_value' => '10.00', 'unit_price' => '81.00', 'line_total' => '162.00',
    ]);
});

test('D85 the database keeps a line price within its list price and a discount complete', function (array $attributes): void {
    expect(fn () => OrderItem::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'above the list price' => [['list_unit_price' => '80.00', 'unit_price' => '90.00', 'manual_discount_type' => 'amount', 'manual_discount_value' => '1']],
    'below a piaster' => [['list_unit_price' => '80.00', 'unit_price' => '0.00', 'manual_discount_type' => 'amount', 'manual_discount_value' => '80']],
    'cheaper without a discount' => [['list_unit_price' => '90.00', 'unit_price' => '80.00']],
    'a type without a value' => [['list_unit_price' => '90.00', 'unit_price' => '80.00', 'manual_discount_type' => 'amount']],
]);

test('BR-O12 BR-O13 a line keeps what was delivered and when it was removed', function (): void {
    $item = OrderItem::factory()->create(['quantity' => 6, 'delivered_quantity' => '4', 'removed_at' => null])->fresh();
    $removed = OrderItem::factory()->removed()->create()->fresh();

    expect($item?->delivered_quantity)->toBe(4)
        ->and($removed?->removed_at)->toBeInstanceOf(Carbon\CarbonImmutable::class);
});

test('BR-O13 the database keeps a delivered quantity within what was ordered, and a removed line undelivered', function (array $attributes): void {
    expect(fn () => OrderItem::factory()->create(['quantity' => 6, ...$attributes]))->toThrow(QueryException::class);
})->with([
    'more than ordered' => [['delivered_quantity' => 7]],
    'negative' => [['delivered_quantity' => -1]],
    'removed and delivered' => [['delivered_quantity' => 6, 'removed_at' => '2026-10-20 10:00:00']],
]);

test('BR-O12 the live scope leaves removed lines out', function (): void {
    $order = Order::factory()->create();
    $live = OrderItem::factory()->for($order)->create();
    OrderItem::factory()->for($order)->removed()->create();

    expect(OrderItem::query()->tap(new App\Modules\Orders\Models\Scopes\Live)->pluck('id')->all())->toBe([$live->id]);
});

test('BR-O14 a line holds stock and has nothing converted unless it is a pre-order line', function (): void {
    $line = OrderItem::factory()->create(['quantity' => 6])->fresh();
    $preorderLine = OrderItem::factory()->preorderLine(converted: 2)->create(['quantity' => 6])->fresh();

    expect($line?->reserved)->toBeTrue()
        ->and($line?->converted_quantity)->toBe(0)
        ->and($line?->remainingQuantity())->toBe(6)
        ->and($preorderLine?->reserved)->toBeFalse()
        ->and($preorderLine?->remainingQuantity())->toBe(4);
});

test('BR-O14 the database keeps the converted quantity within the line and off reserved lines', function (array $attributes, string $constraint): void {
    $line = OrderItem::factory()->create(['quantity' => 6]);

    expect(fn () => DB::table('order_items')->where('id', $line->id)->update($attributes))->toThrow(QueryException::class, $constraint);
})->with([
    'above the quantity' => [['reserved' => false, 'converted_quantity' => 7], 'order_items_converted_within_ordered'],
    'below zero' => [['reserved' => false, 'converted_quantity' => -1], 'order_items_converted_within_ordered'],
    'on a reserved line' => [['converted_quantity' => 1], 'order_items_converted_unreserved'],
]);

test('D91 a line belongs to the SKU unit it was ordered in', function (): void {
    $item = OrderItem::factory()->create();

    expect($item->skuUnit?->id)->toBe($item->sku_unit_id)
        ->and($item->skuUnit?->sku_id)->toBe($item->sku_id);
});

test('BR-P15 D93 a bundle line has its component lines as children, each knowing its parent', function (): void {
    $bundle = OrderItem::factory()->create(['is_bundle' => true, 'reserved' => false]);
    $first = OrderItem::factory()->create(['order_id' => $bundle->order_id, 'parent_item_id' => $bundle->id]);
    $second = OrderItem::factory()->create(['order_id' => $bundle->order_id, 'parent_item_id' => $bundle->id]);

    expect($bundle->children->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->parent?->is($bundle))->toBeTrue()
        ->and($first->isComponent())->toBeTrue()
        ->and($bundle->isComponent())->toBeFalse()
        ->and($bundle->fresh()?->is_bundle)->toBeTrue();
});

test('D93 a bundle line is top level and holds no stock', function (array $attributes): void {
    $parent = OrderItem::factory()->create();

    expect(fn () => OrderItem::factory()->create(['order_id' => $parent->order_id, 'is_bundle' => true, 'reserved' => false, ...$attributes, ...(isset($attributes['parent_item_id']) ? ['parent_item_id' => $parent->id] : [])]))->toThrow(QueryException::class);
})->with([
    'under another line' => [['parent_item_id' => true]],
    'holding stock' => [['reserved' => true]],
]);
