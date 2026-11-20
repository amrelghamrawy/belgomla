<?php

declare(strict_types=1);

use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $item = OrderReturnItem::factory()->create()->fresh();

    expect(array_keys($item?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'order_return_id', 'order_item_id', 'quantity', 'quantity_base', 'unit_price', 'line_total', 'created_at', 'updated_at',
    ]);
});

test('D74 casts: integers and money strings, priced from the order line by default', function (): void {
    $line = OrderItem::factory()->create(['quantity' => 5, 'unit_price' => '85.00']);

    $item = OrderReturnItem::factory()->create(['order_item_id' => $line->id, 'quantity' => '2'])->fresh();

    expect($item?->only(['quantity', 'quantity_base', 'unit_price', 'line_total']))->toBe(['quantity' => 2, 'quantity_base' => 2, 'unit_price' => '85.00', 'line_total' => '170.00']);
});

test('the database refuses a zero quantity', function (): void {
    expect(fn () => OrderReturnItem::factory()->create(['quantity' => 0]))->toThrow(QueryException::class);
});

test('a returned line belongs to its return and its order line', function (): void {
    $return = OrderReturn::factory()->create();
    $line = OrderItem::factory()->create();

    $item = OrderReturnItem::factory()->for($return)->create(['order_item_id' => $line->id]);

    expect($item->orderReturn->is($return))->toBeTrue()
        ->and($item->orderItem->is($line))->toBeTrue();
});
