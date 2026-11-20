<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Exceptions\ReturnExceedsDelivered;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-R02: two clerks returning the last returnable unit of a line must never both succeed.
|
| Same approach as RecordCollectionConcurrencyTest: the race runs on the two extra sessions `pgsql_session_a|b`
| (outside RefreshDatabase's transaction) with committed fixtures of a tenant of their own, deleted after each test.
| A records inside an open transaction; B, with a short `lock_timeout`, must wait on A's lock of the order row rather
| than read the old returnable quantity; once A commits, B retries and finds nothing left.
*/

beforeEach(function (): void {
    [$this->raceTenant, $this->order, $this->line] = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant): array {
            $order = Order::factory()->delivered()->create();
            $line = OrderItem::factory()->for($order)->create(['quantity' => 1, 'conversion_factor' => 1, 'quantity_base' => 1, 'unit_price' => '50.00', 'line_total' => '50.00']);
            $order->forceFill(['items_total' => '50.00', 'order_total' => '50.00'])->save();

            return [$tenant, $order, $line];
        });
    });

    app(TenantContext::class)->set($this->raceTenant);

    $this->data = fn (): ReturnData => new ReturnData('مرتجع', [new ReturnLineData($this->line->id, 1)]);
    $this->returns = fn (): int => onSession('pgsql_session_a', fn (): int => OrderReturn::query()->where('order_id', $this->order->id)->count());
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->raceTenant->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-R02 a second session waits on the order lock while the first returns the last unit, then finds nothing returnable', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn (): OrderReturn => app(RecordReturn::class)->handle($this->order, ($this->data)()));

    $blocked = onSession('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            app(RecordReturn::class)->handle($this->order, ($this->data)());

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn (): OrderReturn => onSession('pgsql_session_b', fn (): OrderReturn => app(RecordReturn::class)->handle($this->order, ($this->data)())))->toThrow(ReturnExceedsDelivered::class)
        ->and(($this->returns)())->toBe(1);
});
