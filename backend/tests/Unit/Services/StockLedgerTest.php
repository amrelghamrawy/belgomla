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

    $movement = ($this->ledger)()->deduct($sku, 6, orderId: 1024);

    expect(($this->stock)($sku)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 4, 'reserved' => 0])
        ->and($movement->only(['type', 'quantity', 'on_hand_after', 'order_id', 'actor_type']))->toBe([
            'type' => MovementType::Sale, 'quantity' => -6, 'on_hand_after' => 4, 'order_id' => 1024, 'actor_type' => ActorType::System,
        ]);
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
]);
