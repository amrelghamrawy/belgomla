<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Exceptions\BelowReserved;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\NotInTransaction;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->ledger = fn (): StockLedger => app(StockLedger::class);
    $this->stock = fn (Sku $sku): SkuInventory => SkuInventory::query()->findOrFail($sku->id);
});

test('lock returns the rows keyed by sku id, locked for update in sku id order', function (): void {
    $second = Sku::factory()->withStock(5)->create();
    $first = Sku::factory()->withStock(9)->create();
    DB::enableQueryLog();

    $rows = ($this->ledger)()->lock([$second->id, $first->id, $second->id]);

    $select = collect(DB::getQueryLog())->pluck('query')->first(fn (string $sql): bool => str_contains($sql, 'for update'));
    expect($rows->keys()->all())->toBe([min($first->id, $second->id), max($first->id, $second->id)])
        ->and($rows->get($first->id)?->on_hand)->toBe(9)
        ->and($select)->toContain('order by "sku_id" asc for update');
});

test('lock creates an empty stock row for a sku that has none', function (): void {
    $sku = Sku::factory()->create();

    $row = ($this->ledger)()->lock([$sku->id])->get($sku->id);

    expect($row?->only(['sku_id', 'tenant_id', 'on_hand', 'reserved']))->toBe(['sku_id' => $sku->id, 'tenant_id' => $this->tenant->id, 'on_hand' => 0, 'reserved' => 0]);
});

test('BR-T04 lock never creates or returns rows for another tenant\'s sku', function (): void {
    $foreign = $this->inTenant(fn () => Sku::factory()->withStock(5)->create(), $this->otherTenant());

    expect(($this->ledger)()->lock([$foreign->id]))->toBeEmpty();
    $this->assertDatabaseCount('sku_inventory', 1);
});

test('BR-INV06 opening stock sets on hand and writes an opening movement by the system', function (): void {
    $sku = Sku::factory()->create();

    ($this->ledger)()->openingStock($sku, 100);

    expect(($this->stock)($sku)->on_hand)->toBe(100)
        ->and(InventoryMovement::query()->sole()->only(['sku_id', 'type', 'quantity', 'on_hand_after', 'reason', 'order_id', 'actor_type', 'actor_id']))->toBe([
            'sku_id' => $sku->id, 'type' => MovementType::Opening, 'quantity' => 100, 'on_hand_after' => 100,
            'reason' => null, 'order_id' => null, 'actor_type' => ActorType::System, 'actor_id' => null,
        ]);
});

test('zero opening stock creates the empty row and no movement', function (): void {
    $sku = Sku::factory()->create();

    ($this->ledger)()->openingStock($sku, 0);

    expect(($this->stock)($sku)->on_hand)->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV06 an adjustment writes the signed change, the resulting on hand, the reason and the user', function (int $newOnHand, int $quantity): void {
    $user = $this->makeOwner();
    $sku = Sku::factory()->withStock(50)->create();

    $movement = ($this->ledger)()->adjust($sku, $newOnHand, 'جرد', $user->id);

    expect(($this->stock)($sku)->on_hand)->toBe($newOnHand)
        ->and($movement?->only(['type', 'quantity', 'on_hand_after', 'reason', 'actor_type', 'actor_id']))->toBe([
            'type' => MovementType::Adjustment, 'quantity' => $quantity, 'on_hand_after' => $newOnHand,
            'reason' => 'جرد', 'actor_type' => ActorType::TenantUser, 'actor_id' => $user->id,
        ]);
})->with([
    'up' => [80, 30],
    'down' => [20, -30],
    'D35 down to zero with nothing reserved' => [0, -50],
]);

test('D35 adjusting to the current on hand changes nothing', function (): void {
    $sku = Sku::factory()->withStock(50)->create();

    expect(($this->ledger)()->adjust($sku, 50, 'جرد'))->toBeNull()
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV08 an adjustment may go down to exactly the reserved quantity', function (): void {
    $sku = Sku::factory()->withStock(50, 20)->create();

    ($this->ledger)()->adjust($sku, 20, 'جرد');

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 20, 'reserved' => 20]);
});

test('BR-INV08 an adjustment below the reserved quantity is refused with the holding orders and writes nothing', function (): void {
    $sku = Sku::factory()->withStock(100, 80)->create();
    $orders = [['number' => 1024, 'status' => 'confirmed', 'quantity_base' => 60], ['number' => 1031, 'status' => 'pending', 'quantity_base' => 20]];
    app()->instance(ReservationHolders::class, new readonly class($orders) implements ReservationHolders
    {
        /** @param list<array{number: int, status: string, quantity_base: int}> $orders */
        public function __construct(private array $orders) {}

        public function ordersHolding(Sku $sku): array
        {
            return $this->orders;
        }
    });

    expect(fn () => ($this->ledger)()->adjust($sku, 50, 'جرد'))
        ->toThrow(function (BelowReserved $e) use ($orders): void {
            expect($e->details)->toBe(['reserved' => 80, 'min_allowed' => 80, 'orders' => $orders]);
        });

    expect(($this->stock)($sku)->on_hand)->toBe(100)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV03 a reservation of up to the available units is held without a movement', function (int $quantity): void {
    $sku = Sku::factory()->withStock(10, 4)->create();

    ($this->ledger)()->reserve($sku, $quantity);

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 10, 'reserved' => 4 + $quantity])
        ->and(InventoryMovement::query()->count())->toBe(0);
})->with(['one unit' => [1], 'exactly the available units' => [6]]);

test('BR-INV01 reserving one unit more than available is refused with insufficient_stock', function (): void {
    $sku = Sku::factory()->withStock(10, 4)->create();

    expect(fn () => ($this->ledger)()->reserve($sku, 7))
        ->toThrow(function (InsufficientStock $e) use ($sku): void {
            expect($e->errorCode)->toBe('insufficient_stock')
                ->and($e->items)->toBe([['sku_id' => $sku->id, 'reason' => 'insufficient_stock', 'available' => 6]]);
        });

    expect(($this->stock)($sku)->reserved)->toBe(4);
});

test('BR-INV01 a sku without a stock row cannot be reserved', function (): void {
    $sku = Sku::factory()->create();

    ($this->ledger)()->reserve($sku, 1);
})->throws(InsufficientStock::class);

test('BR-INV04 a release gives the reservation back without a movement', function (): void {
    $sku = Sku::factory()->withStock(10, 6)->create();

    ($this->ledger)()->release($sku, 6);

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 10, 'reserved' => 0])
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV05 a deduction takes the units from on hand and reserved as a negative sale movement of the order', function (): void {
    $sku = Sku::factory()->withStock(10, 6)->create();
    $order = Order::factory()->create();

    $movement = ($this->ledger)()->deduct($sku, 6, orderId: $order->id);

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 4, 'reserved' => 0])
        ->and($movement->only(['type', 'quantity', 'on_hand_after', 'order_id', 'actor_type']))->toBe([
            'type' => MovementType::Sale, 'quantity' => -6, 'on_hand_after' => 4, 'order_id' => $order->id, 'actor_type' => ActorType::System,
        ]);
});

test('BR-R03 a restock puts returned units back on hand as a positive return movement of the order', function (): void {
    $sku = Sku::factory()->withStock(10, 4)->create();
    $order = Order::factory()->delivered()->create();

    $movement = ($this->ledger)()->restock($sku, 6, $order->id, null);

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 16, 'reserved' => 4])
        ->and($movement->only(['sku_id', 'type', 'quantity', 'on_hand_after', 'order_id', 'reason', 'actor_type', 'actor_id']))->toBe([
            'sku_id' => $sku->id, 'type' => MovementType::Return, 'quantity' => 6, 'on_hand_after' => 16, 'order_id' => $order->id,
            'reason' => null, 'actor_type' => ActorType::System, 'actor_id' => null,
        ]);
});

test('BR-R06 an unrestock takes voided returned units off again as a negative return movement by the merchant', function (): void {
    $owner = $this->makeOwner();
    $sku = Sku::factory()->withStock(10, 4)->create();
    $order = Order::factory()->delivered()->create();

    $movement = ($this->ledger)()->unrestock($sku, 6, $order->id, $owner->id);

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 4, 'reserved' => 4])
        ->and($movement->only(['type', 'quantity', 'on_hand_after', 'order_id', 'actor_type', 'actor_id']))->toBe([
            'type' => MovementType::Return, 'quantity' => -6, 'on_hand_after' => 4, 'order_id' => $order->id,
            'actor_type' => ActorType::TenantUser, 'actor_id' => $owner->id,
        ]);
});

test('D76 an unrestock never takes more than is available, so reserved or sold units stay', function (): void {
    $sku = Sku::factory()->withStock(10, 5)->create();
    $order = Order::factory()->delivered()->create();

    expect(fn (): InventoryMovement => ($this->ledger)()->unrestock($sku, 6, $order->id))->toThrow(function (InsufficientStock $e) use ($sku): void {
        expect([$e->status, $e->errorCode, $e->items])->toBe([422, 'insufficient_stock', [['sku_id' => $sku->id, 'reason' => 'insufficient_stock', 'available' => 5]]]);
    });

    expect(($this->stock)($sku)->on_hand)->toBe(10)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('release and deduct never take back more than is reserved', function (Closure $call): void {
    $sku = Sku::factory()->withStock(10, 2)->create();

    $call(app(StockLedger::class), $sku);
})->with([
    'release' => [fn (StockLedger $ledger, Sku $sku) => $ledger->release($sku, 3)],
    'deduct' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->deduct($sku, 3, orderId: 1)],
])->throws(RuntimeException::class, 'has 2 reserved');

test('stock quantities must be positive and on hand never negative', function (Closure $call, string $message): void {
    $sku = Sku::factory()->withStock(10, 2)->create();

    expect(fn () => $call(app(StockLedger::class), $sku))->toThrow(RuntimeException::class, $message);
})->with([
    'reserve zero' => [fn (StockLedger $ledger, Sku $sku) => $ledger->reserve($sku, 0), 'must be positive'],
    'release zero' => [fn (StockLedger $ledger, Sku $sku) => $ledger->release($sku, 0), 'must be positive'],
    'deduct negative' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->deduct($sku, -1, 1), 'must be positive'],
    'restock zero' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->restock($sku, 0, 1), 'must be positive'],
    'unrestock negative' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->unrestock($sku, -2, 1), 'must be positive'],
    'negative opening stock' => [fn (StockLedger $ledger, Sku $sku) => $ledger->openingStock($sku, -1), 'cannot be negative'],
    'negative adjustment' => [fn (StockLedger $ledger, Sku $sku): ?\App\Modules\Inventory\Models\InventoryMovement => $ledger->adjust($sku, -1, 'x'), 'cannot be negative'],
]);

test('Tech §11 locking and reservation changes refuse to run outside a transaction', function (Closure $call, string $operation): void {
    $sku = Sku::factory()->make(['id' => 1]);

    // RefreshDatabase wraps the default connection in a transaction, so use a fresh session without one.
    expect(fn (): mixed => onSession('pgsql_session_a', fn () => $call(app(StockLedger::class), $sku)))
        ->toThrow(NotInTransaction::class, "StockLedger::{$operation}() must run inside a database transaction");
})->with([
    'lock' => [fn (StockLedger $ledger, Sku $sku): Illuminate\Database\Eloquent\Collection => $ledger->lock([$sku->id]), 'lock'],
    'reserve' => [fn (StockLedger $ledger, Sku $sku) => $ledger->reserve($sku, 1), 'reserve'],
    'release' => [fn (StockLedger $ledger, Sku $sku) => $ledger->release($sku, 1), 'release'],
    'deduct' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->deduct($sku, 1, 1), 'deduct'],
    'restock' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->restock($sku, 1, 1), 'restock'],
    'unrestock' => [fn (StockLedger $ledger, Sku $sku): InventoryMovement => $ledger->unrestock($sku, 1, 1), 'unrestock'],
]);

test('BR-INV09 a receipt adds the units as a positive receive movement at the cost paid and moves the average', function (): void {
    $owner = $this->makeOwner();
    $sku = Sku::factory()->withStock(100, 30, avgCost: '5.0000')->create();

    $movement = ($this->ledger)()->receive($sku, 100, '6.0000', 'مكتبة النور', $owner->id);

    // (100 × 5 + 100 × 6) ÷ 200 = 5.5000
    expect(($this->stock)($sku)->only(['on_hand', 'reserved', 'avg_cost']))->toBe(['on_hand' => 200, 'reserved' => 30, 'avg_cost' => '5.5000'])
        ->and($movement->only(['sku_id', 'type', 'quantity', 'on_hand_after', 'reason', 'unit_cost', 'order_id', 'actor_type', 'actor_id']))->toBe([
            'sku_id' => $sku->id, 'type' => MovementType::Receive, 'quantity' => 100, 'on_hand_after' => 200, 'reason' => 'مكتبة النور',
            'unit_cost' => '6.0000', 'order_id' => null, 'actor_type' => ActorType::TenantUser, 'actor_id' => $owner->id,
        ]);
});

test('BR-INV09 the first receipt of a sku without a stock row or a cost sets the average to the cost paid', function (): void {
    $sku = Sku::factory()->create();

    ($this->ledger)()->receive($sku, 12, '7.2500');

    expect(($this->stock)($sku)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 12, 'avg_cost' => '7.2500']);
});

test('BR-INV09 a receipt of nothing is refused', function (): void {
    $sku = Sku::factory()->withStock(10)->create();

    expect(fn (): InventoryMovement => ($this->ledger)()->receive($sku, 0, '1.0000'))->toThrow(RuntimeException::class, 'must be positive');

    expect(InventoryMovement::query()->count())->toBe(0);
});

test('D96 c an adjustment moves at the average cost either way and never changes it', function (int $newOnHand): void {
    $sku = Sku::factory()->withStock(50, avgCost: '4.1250')->create();

    $movement = ($this->ledger)()->adjust($sku, $newOnHand, 'كسر');

    expect($movement?->unit_cost)->toBe('4.1250')
        ->and(($this->stock)($sku)->avg_cost)->toBe('4.1250');
})->with(['down' => [45], 'up' => [60]]);

test('D96 e an adjustment of a sku without a cost moves at no cost', function (): void {
    $sku = Sku::factory()->withStock(50)->create();

    expect(($this->ledger)()->adjust($sku, 40, 'كسر')?->unit_cost)->toBeNull();
});

test('BR-INV09 a deduction moves at the average of that moment and leaves the average', function (): void {
    $sku = Sku::factory()->withStock(10, 6, avgCost: '3.3333')->create();
    $order = Order::factory()->create();

    $movement = ($this->ledger)()->deduct($sku, 6, orderId: $order->id);

    expect($movement->unit_cost)->toBe('3.3333')
        ->and(($this->stock)($sku)->avg_cost)->toBe('3.3333');
});

test('BR-INV09 a restock at the sale\'s frozen cost moves the average like a receipt', function (): void {
    $sku = Sku::factory()->withStock(30, avgCost: '8.0000')->create();
    $order = Order::factory()->delivered()->create();

    $movement = ($this->ledger)()->restock($sku, 10, $order->id, unitCost: '6.0000');

    // (30 × 8 + 10 × 6) ÷ 40 = 300 ÷ 40 = 7.5000
    expect($movement->unit_cost)->toBe('6.0000')
        ->and(($this->stock)($sku)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 40, 'avg_cost' => '7.5000']);
});

test('D96 c a restock of a sale without a cost leaves the average', function (): void {
    $sku = Sku::factory()->withStock(30, avgCost: '8.0000')->create();
    $order = Order::factory()->delivered()->create();

    $movement = ($this->ledger)()->restock($sku, 10, $order->id);

    expect($movement->unit_cost)->toBeNull()
        ->and(($this->stock)($sku)->avg_cost)->toBe('8.0000');
});

test('D96 c an unrestock moves at the cost the goods came back at and leaves the average', function (): void {
    $sku = Sku::factory()->withStock(40, avgCost: '7.5000')->create();
    $order = Order::factory()->delivered()->create();

    $movement = ($this->ledger)()->unrestock($sku, 10, $order->id, unitCost: '6.0000');

    expect($movement->unit_cost)->toBe('6.0000')
        ->and(($this->stock)($sku)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 30, 'avg_cost' => '7.5000']);
});

test('D96 c stock reaching zero keeps the last average', function (): void {
    $sku = Sku::factory()->withStock(5, 5, avgCost: '2.5000')->create();
    $order = Order::factory()->create();

    ($this->ledger)()->deduct($sku, 5, orderId: $order->id);

    expect(($this->stock)($sku)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 0, 'avg_cost' => '2.5000']);
});

test('D96 d setting the cost replaces the average without a movement and returns the one it replaced', function (?string $old): void {
    $sku = Sku::factory()->withStock(20, avgCost: $old)->create();

    expect(($this->ledger)()->setCost($sku, '4.7500'))->toBe($old)
        ->and(($this->stock)($sku)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 20, 'avg_cost' => '4.7500'])
        ->and(InventoryMovement::query()->count())->toBe(0);
})->with(['no cost yet' => [null], 'a wrong cost' => ['9.0000']]);

test('D96 e opening stock moves at no cost', function (): void {
    $sku = Sku::factory()->create();

    ($this->ledger)()->openingStock($sku, 10);

    expect(InventoryMovement::query()->sole()->unit_cost)->toBeNull()
        ->and(($this->stock)($sku)->avg_cost)->toBeNull();
});
