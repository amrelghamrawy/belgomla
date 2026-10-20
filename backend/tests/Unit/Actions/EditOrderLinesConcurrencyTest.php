<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Actions\EditOrderLines;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\EditedOrder;
use App\Modules\Orders\Data\OrderEditData;
use App\Modules\Orders\Data\OrderEditLineData;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| BR-O12, BR-INV03: the merchant raises a line of one customer's order while another customer checks out the same SKU,
| for the last units: exactly one gets them. Same approach as PlaceOrderConcurrencyTest: committed fixtures of a tenant
| of their own on `pgsql_session_a` / `pgsql_session_b`, deleted after each test; the race is interleaved in one
| process. The first runs inside an outer transaction it keeps open; the second starts with a short `lock_timeout` and
| must wait on the stock row lock; the first commits; the second retries and is refused with `insufficient_stock`.
*/

beforeEach(function (): void {
    Queue::fake();

    $this->race = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'edit'.random_int(1000, 999999)]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant): array {
            // 10 cartons of 20: the phoned order holds 5, 5 are left; the edit wants all 10, the shopper 5.
            $sku = Product::factory()->withSku(['conversion_factor' => 20, 'moq' => 1], onHand: 200)->create()->sku()->sole();
            $phoned = Customer::factory()->create();
            $shopper = Customer::factory()->create();
            CartItem::factory()->for(Cart::factory()->for($phoned))->create(['sku_id' => $sku->id, 'quantity' => 5]);
            $order = app(Checkout::class)->handle($phoned, new CheckoutData(CustomerAddress::factory()->for($phoned)->default()->create()->id));
            CartItem::factory()->for(Cart::factory()->for($shopper))->create(['sku_id' => $sku->id, 'quantity' => 5]);

            return [
                'tenant' => $tenant,
                'sku' => $sku,
                'user' => TenantUser::factory()->create(),
                'order' => $order,
                'line' => OrderItem::query()->where('order_id', $order->id)->sole(),
                'shopper' => [$shopper, CustomerAddress::factory()->for($shopper)->default()->create()],
            ];
        });
    });

    app(TenantContext::class)->set($this->race['tenant']);

    $this->edit = fn (): EditedOrder => app(EditOrderLines::class)->handle(
        $this->race['order'],
        new OrderEditData([new OrderEditLineData($this->race['line']->id, $this->race['sku']->id, 10)]),
        $this->race['user']->id,
    );
    $this->checkout = fn (): Order => app(Checkout::class)->handle($this->race['shopper'][0], new CheckoutData($this->race['shopper'][1]->id));
    $this->stock = fn (): array => onSession('pgsql_session_a', fn (): array => SkuInventory::query()->findOrFail($this->race['sku']->id)->only(['on_hand', 'reserved']));
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->race['tenant']->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-O12 BR-INV03 an edit and a checkout race for the last units: the checkout waits on the row lock, then gets insufficient_stock', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn (): EditedOrder => ($this->edit)());

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
            expect($e->items)->toBe([['sku_id' => $this->race['sku']->id, 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 0]]);
        })
        ->and(($this->stock)())->toBe(['on_hand' => 200, 'reserved' => 200]);
});

test('BR-O12 BR-INV03 a checkout first leaves the edit refused and the order as it was', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn (): Order => ($this->checkout)());
    $a->commit();

    expect(fn (): mixed => onSession('pgsql_session_b', fn (): EditedOrder => ($this->edit)()))->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => $this->race['sku']->id, 'reason' => 'insufficient_stock', 'requested' => 10, 'available' => 5]]);
    })
        ->and(($this->stock)())->toBe(['on_hand' => 200, 'reserved' => 200])
        ->and(onSession('pgsql_session_a', fn (): int => OrderItem::query()->findOrFail($this->race['line']->id)->quantity))->toBe(5);
});
