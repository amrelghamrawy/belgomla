<?php

declare(strict_types=1);

use App\Modules\Collections\Actions\ApplyCustomerCredit;
use App\Modules\Collections\Exceptions\ExceedsCredit;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-C07: two cashiers spending the same credit on two orders must never both succeed.
|
| Same approach as RecordCollectionConcurrencyTest: the race runs on `pgsql_session_a|b` with committed fixtures of a
| tenant of their own, deleted after each test. A applies inside an open transaction; B, applying the same credit to
| another order with a short `lock_timeout`, must wait on A's lock of the credit row; once A commits, nothing is left.
*/

beforeEach(function (): void {
    [$this->raceTenant, $this->credit, $this->orderA, $this->orderB] = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant): array {
            $customer = Customer::factory()->create();
            $order = fn (): Order => Order::factory()->delivered()->for($customer)->create(['items_total' => '500.00', 'order_total' => '500.00']);

            return [$tenant, CustomerCredit::factory()->for($customer)->create(['amount' => '100.00']), $order(), $order()];
        });
    });

    app(TenantContext::class)->set($this->raceTenant);
    $this->remaining = fn (): string => onSession('pgsql_session_a', fn (): string => CustomerCredit::query()->findOrFail($this->credit->id)->remaining);
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->raceTenant->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-C07 a second session waits on the credit lock while the first spends it, then finds nothing left', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn (): CashCollection => app(ApplyCustomerCredit::class)->handle($this->orderA, $this->credit, '100.00'));

    $blocked = onSession('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            app(ApplyCustomerCredit::class)->handle($this->orderB, $this->credit, '100.00');

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn (): CashCollection => onSession('pgsql_session_b', fn (): CashCollection => app(ApplyCustomerCredit::class)->handle($this->orderB, $this->credit, '100.00')))->toThrow(ExceedsCredit::class)
        ->and(($this->remaining)())->toBe('0.00');
});
