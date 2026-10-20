<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $item = OrderItem::factory()->create()->fresh();

    expect(array_keys($item?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'order_id', 'sku_id', 'product_id', 'product_name', 'sku_code', 'sku_name', 'unit_name',
        'conversion_factor', 'quantity', 'quantity_base', 'cover_price', 'unit_price', 'discount_percent',
        'tier_min_quantity', 'line_total', 'created_at', 'updated_at', 'list_unit_price', 'manual_discount_type', 'manual_discount_value',
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
