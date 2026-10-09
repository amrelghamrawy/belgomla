<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-INV03 / Tech §11: two checkouts racing for the last units must never both reserve them.
|
| How this is tested (rules/01 §4 "concurrency-sensitive code"):
|  - RefreshDatabase keeps the default connection inside one uncommitted transaction that no other
|    session can see or lock against, so the race runs on two extra sessions, `pgsql_session_a` and
|    `pgsql_session_b` (config/database.php), pointed at this process's test database by onSession().
|    Their fixtures are committed: a tenant of their own (not `alnoor`, whose uncommitted row would
|    block the unique subdomain index), deleted again after each test (its rows cascade).
|  - PHP on Windows has no pcntl, so the race is interleaved in one process instead of forked:
|    A opens a transaction and reserves; while A is still open, B tries the same SKU with a short
|    `lock_timeout` and must wait on A's row lock (it times out rather than reading stale stock);
|    after A commits, B retries in a new transaction and sees what A left.
*/

beforeEach(function (): void {
    [$this->raceTenant, $this->sku] = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);
        $sku = app(TenantContext::class)->runAs($tenant, fn (): Sku => Product::factory()->withSku(onHand: 5)->create()->sku()->sole());

        return [$tenant, $sku];
    });

    app(TenantContext::class)->set($this->raceTenant);
    $this->ledger = app(StockLedger::class);

    /** Reserve on a session inside its own transaction, committed. */
    $this->reserveOn = fn (string $session, int $quantity): mixed => onSession($session, fn (Connection $db): mixed => $db->transaction(fn (): mixed => $this->ledger->reserve($this->sku, $quantity)));

    $this->stock = fn (): SkuInventory => onSession('pgsql_session_a', fn (): SkuInventory => SkuInventory::query()->findOrFail($this->sku->id));
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->raceTenant->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-INV03 a second session waits on the row lock while the first reserves the last units, then finds nothing left', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn () => $this->ledger->reserve($this->sku, 5));

    $blocked = onSession('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            $this->ledger->reserve($this->sku, 5);

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn () => ($this->reserveOn)('pgsql_session_b', 5))->toThrow(InsufficientStock::class)
        ->and(($this->stock)()->only(['on_hand', 'reserved']))->toBe(['on_hand' => 5, 'reserved' => 5]);
});

test('BR-INV01 two sessions asking for more than is left together: the first wins, the second is refused', function (): void {
    ($this->reserveOn)('pgsql_session_a', 3);

    expect(fn () => ($this->reserveOn)('pgsql_session_b', 3))
        ->toThrow(function (InsufficientStock $e): void {
            expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'available' => 2]]);
        })
        ->and(($this->stock)()->reserved)->toBe(3);
});

test('BR-INV03 two sessions that fit together both reserve', function (): void {
    ($this->reserveOn)('pgsql_session_a', 2);
    ($this->reserveOn)('pgsql_session_b', 3);

    expect(($this->stock)()->only(['on_hand', 'reserved']))->toBe(['on_hand' => 5, 'reserved' => 5]);
});
