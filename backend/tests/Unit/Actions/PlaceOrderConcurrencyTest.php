<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Actions\PlaceOrderForCustomer;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\MerchantOrderData;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-O11, BR-INV03: a merchant's order for one customer and a storefront checkout of another, for the last units at
| the same time: exactly one order. Same approach as CheckoutConcurrencyTest: committed fixtures of a tenant of their
| own on `pgsql_session_a` / `pgsql_session_b`, deleted after each test; the race is interleaved in one process. A (the
| merchant) runs the whole order inside an outer transaction it keeps open; B (the shopper) starts checkout with a
| short `lock_timeout` and must wait on A's stock row lock; A commits; B retries and is refused with `insufficient_stock`.
*/

beforeEach(function (): void {
    $this->race = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant): array {
            // 5 cartons of 20 left; each order wants all 5.
            $sku = Product::factory()->withSku(['conversion_factor' => 20, 'moq' => 1], onHand: 100)->create()->skus()->sole();
            $phoned = Customer::factory()->create();
            $shopper = Customer::factory()->create();
            CartItem::factory()->for(Cart::factory()->for($shopper))->create(['sku_id' => $sku->id, 'quantity' => 5]);

            return [
                'tenant' => $tenant,
                'sku' => $sku,
                'user' => TenantUser::factory()->create(),
                'phoned' => [$phoned, CustomerAddress::factory()->for($phoned)->default()->create()],
                'shopper' => [$shopper, CustomerAddress::factory()->for($shopper)->default()->create()],
            ];
        });
    });

    app(TenantContext::class)->set($this->race['tenant']);

    $this->merchantOrder = fn (): Order => app(PlaceOrderForCustomer::class)->handle(
        new MerchantOrderData($this->race['phoned'][0]->id, $this->race['phoned'][1]->id, [['sku_unit_id' => $this->defaultUnitId($this->race['sku']), 'quantity' => 5]]),
        $this->race['user']->id,
    );
    $this->checkout = fn (): Order => app(Checkout::class)->handle($this->race['shopper'][0], new CheckoutData($this->race['shopper'][1]->id));
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->race['tenant']->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-O11 BR-INV03 a merchant order and a checkout for the last units: the checkout waits on the row lock, then gets insufficient_stock', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    $first = onSession('pgsql_session_a', fn (): Order => ($this->merchantOrder)());

    $blocked = onSession('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            ($this->checkout)();

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn (): mixed => onSession('pgsql_session_b', fn (): Order => ($this->checkout)()))->toThrow(function (CheckoutFailed $e): void {
            expect($e->items)->toBe([['sku_id' => $this->race['sku']->id, 'sku_unit_id' => $this->defaultUnitId($this->race['sku']), 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 0]]);
        })
        ->and(onSession('pgsql_session_a', fn (): array => [
            Order::query()->pluck('number')->all(),
            SkuInventory::query()->findOrFail($this->race['sku']->id)->only(['on_hand', 'reserved']),
        ]))->toBe([[$first->number], ['on_hand' => 100, 'reserved' => 100]]);
});

test('BR-O11 BR-INV03 a checkout first leaves the merchant order refused', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn (): Order => ($this->checkout)());
    $a->commit();

    expect(fn (): mixed => onSession('pgsql_session_b', fn (): Order => ($this->merchantOrder)()))->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->race['sku']->id, 'sku_unit_id' => $this->defaultUnitId($this->race['sku']), 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 0]]);
    })
        ->and(onSession('pgsql_session_a', fn (): int => Order::query()->count()))->toBe(1);
});
