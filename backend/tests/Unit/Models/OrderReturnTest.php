<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $return = OrderReturn::factory()->create()->fresh();

    expect(array_keys($return?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'order_id', 'number', 'total', 'refund_amount', 'reason', 'returned_at', 'recorded_by',
        'voided_at', 'void_reason', 'voided_by', 'created_at', 'updated_at',
    ]);
});

test('casts: integers, money strings and immutable timestamps', function (): void {
    $return = OrderReturn::factory()->voided()->create(['number' => '2', 'total' => 170, 'refund_amount' => '20.5'])->fresh();

    expect($return?->only(['number', 'total', 'refund_amount']))->toBe(['number' => 2, 'total' => '170.00', 'refund_amount' => '20.50'])
        ->and($return?->returned_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($return?->voided_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a new return has refunded nothing', function (): void {
    expect(new OrderReturn()->refund_amount)->toBe('0.00');
});

test('BR-R05 the database refuses a zero total, a negative refund, a duplicate number or a void without a reason', function (array $attributes): void {
    $order = Order::factory()->delivered()->create();
    OrderReturn::factory()->for($order)->create();

    expect(fn () => OrderReturn::factory()->for($order)->create(['number' => 2, ...$attributes]))->toThrow(QueryException::class);
})->with([
    'zero total' => [['total' => '0.00']],
    'negative refund' => [['refund_amount' => '-1.00']],
    'duplicate number' => [['number' => 1]],
    'void without reason' => [['voided_at' => now()]],
]);

test('a return belongs to its order and has its lines in order', function (): void {
    $return = OrderReturn::factory()->create();
    $second = OrderReturnItem::factory()->for($return)->create();
    $first = OrderReturnItem::factory()->for($return)->create();

    expect($return->order->is(Order::query()->findOrFail($return->order_id)))->toBeTrue()
        ->and($return->items->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('BR-R06 an order lists its returns by number, voided ones included', function (): void {
    $order = Order::factory()->delivered()->create();
    $second = OrderReturn::factory()->for($order)->voided()->create(['number' => 2]);
    $first = OrderReturn::factory()->for($order)->create(['number' => 1]);

    expect($order->returns->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('BR-O08 the net total of an order is its total minus what came back', function (): void {
    expect(Order::factory()->make(['order_total' => '500.00', 'returned_total' => '120.50'])->netTotal())->toBe('379.50');
});

test('BR-R05 the database keeps the returned total between zero and the order total', function (string $returned): void {
    expect(fn () => Order::factory()->create(['items_total' => '100.00', 'order_total' => '100.00', 'returned_total' => $returned]))->toThrow(QueryException::class);
})->with(['negative' => ['-1.00'], 'above the total' => ['100.01']]);
