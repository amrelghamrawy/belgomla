<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Actions\PlacePreorder;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-O14, D88: two customers pre-order the last units under the merchant's cap at the same time: exactly one pre-order.
| Same approach as PlaceOrderConcurrencyTest: committed fixtures of a tenant of their own on `pgsql_session_a` /
| `pgsql_session_b`, deleted after each test; the race is interleaved in one process. A confirms inside an outer
| transaction it keeps open (holding the product row lock); B starts with a short `lock_timeout` and must wait on the
| product row; A commits; B retries and is refused with `preorder_limit` and nothing remaining.
*/

beforeEach(function (): void {
    $this->race = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant): array {
            // A cap of 5 cartons; each customer wants all 5.
            $sku = Product::factory()->withSku(['conversion_factor' => 20, 'moq' => 1])->preorderable(limit: 5)->create()->sku()->sole();
            $customers = [];

            foreach (['a', 'b'] as $key) {
                $customer = Customer::factory()->create();
                CartItem::factory()->preorder()->for(Cart::factory()->for($customer))->create(['sku_id' => $sku->id, 'quantity' => 5]);
                $customers[$key] = [$customer, CustomerAddress::factory()->for($customer)->default()->create()];
            }

            return ['tenant' => $tenant, 'sku' => $sku, ...$customers];
        });
    });

    app(TenantContext::class)->set($this->race['tenant']);

    $this->place = fn (string $who): Order => app(PlacePreorder::class)->handle($this->race[$who][0], new CheckoutData($this->race[$who][1]->id));
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->race['tenant']->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-O14 two pre-orders for the last units under the cap: the second waits on the product lock, then gets preorder_limit', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    $first = onSession('pgsql_session_a', fn (): Order => ($this->place)('a'));

    $blocked = onSession('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            ($this->place)('b');

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn (): mixed => onSession('pgsql_session_b', fn (): Order => ($this->place)('b')))->toThrow(function (CheckoutFailed $e): void {
            expect($e->items)->toBe([['sku_id' => $this->race['sku']->id, 'reason' => 'preorder_limit', 'requested' => 5, 'remaining' => 0]]);
        })
        ->and(onSession('pgsql_session_a', fn (): array => [
            Order::query()->pluck('number')->all(),
            CartItem::query()->where('kind', CartLineKind::Preorder)->count(),
        ]))->toBe([[$first->number], 1]);
});
