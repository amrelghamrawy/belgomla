<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-INV03 / Tech §11: two customers checking out the last units at the same time: exactly one order.
|
| Same approach as tests/Unit/Services/StockLedgerConcurrencyTest.php: RefreshDatabase's transaction is invisible
| to other sessions, so the race runs on `pgsql_session_a` and `pgsql_session_b` with committed fixtures of a
| tenant of their own, deleted after each test. Without pcntl on Windows the race is interleaved in one process:
| A runs the whole checkout inside an outer transaction it keeps open; B starts the same checkout with a short
| `lock_timeout` and must wait on A's stock row lock (it times out instead of reading stale stock); A commits; B
| retries and is refused with `insufficient_stock`.
*/

beforeEach(function (): void {
    $this->race = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant): array {
            // 5 cartons of 20 left; each customer wants all 5.
            $sku = Product::factory()->withSku(['conversion_factor' => 20, 'moq' => 1], onHand: 100)->create()->sku()->sole();
            $buyer = function () use ($sku): array {
                $customer = Customer::factory()->create();
                CartItem::factory()->for(Cart::factory()->for($customer))->create(['sku_id' => $sku->id, 'quantity' => 5]);

                return [$customer, CustomerAddress::factory()->for($customer)->default()->create()];
            };

            return ['tenant' => $tenant, 'sku' => $sku, 'a' => $buyer(), 'b' => $buyer()];
        });
    });

    app(TenantContext::class)->set($this->race['tenant']);

    $this->checkout = fn (string $buyer): Order => app(Checkout::class)->handle($this->race[$buyer][0], new CheckoutData($this->race[$buyer][1]->id));
    $this->on = fn (string $session, Closure $callback): mixed => onSession($session, $callback);
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->race['tenant']->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-INV03 two checkouts for the last units: the second waits on the row lock, then gets insufficient_stock', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    $first = ($this->on)('pgsql_session_a', fn (): Order => ($this->checkout)('a'));

    $blocked = ($this->on)('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            ($this->checkout)('b');

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn () => ($this->on)('pgsql_session_b', fn (): Order => ($this->checkout)('b')))->toThrow(function (CheckoutFailed $e): void {
            expect($e->items)->toBe([['sku_id' => $this->race['sku']->id, 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 0]]);
        })
        ->and(($this->on)('pgsql_session_a', fn (): array => [
            Order::query()->pluck('number')->all(),
            SkuInventory::query()->findOrFail($this->race['sku']->id)->only(['on_hand', 'reserved']),
            CartItem::query()->count(),
        ]))->toBe([[$first->number], ['on_hand' => 100, 'reserved' => 100], 1]);
});

test('BR-O09 two checkouts of one tenant one after the other get consecutive numbers', function (): void {
    SkuInventory::on('pgsql_session_a')->whereKey($this->race['sku']->id)->update(['on_hand' => 200]);

    $numbers = [
        ($this->on)('pgsql_session_a', fn (Connection $db): int => $db->transaction(fn (): int => ($this->checkout)('a')->number)),
        ($this->on)('pgsql_session_b', fn (Connection $db): int => $db->transaction(fn (): int => ($this->checkout)('b')->number)),
    ];

    expect($numbers)->toBe([1, 2]);
});
