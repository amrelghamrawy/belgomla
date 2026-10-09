<?php

declare(strict_types=1);

use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Exceptions\ExceedsBalance;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-C03 / 07-domain-model.md §8: two cashiers recording the same remaining balance must never both succeed.
|
| Same approach as StockLedgerConcurrencyTest: the race runs on the two extra sessions `pgsql_session_a|b`
| (outside RefreshDatabase's transaction) with committed fixtures of a tenant of their own, deleted after each
| test. A records inside an open transaction; B, with a short `lock_timeout`, must wait on A's lock of the order
| row rather than read the old balance; once A commits, B retries and finds nothing left.
*/

beforeEach(function (): void {
    [$this->raceTenant, $this->order] = onSession('pgsql_session_a', function (): array {
        $tenant = Tenant::factory()->create(['subdomain' => 'race'.random_int(1000, 999999)]);
        $order = app(TenantContext::class)->runAs($tenant, fn (): Order => Order::factory()->delivered()->create(['items_total' => '500.00', 'order_total' => '500.00']));

        return [$tenant, $order];
    });

    app(TenantContext::class)->set($this->raceTenant);

    $this->recordOn = fn (string $session, string $amount): CashCollection => onSession($session, fn (Connection $db): CashCollection => $db->transaction(
        fn (): CashCollection => app(RecordCollection::class)->handle($this->order, new CollectionData($amount)),
    ));
    $this->collected = fn (): string => onSession('pgsql_session_a', fn (): string => Order::query()->findOrFail($this->order->id)->collected_amount);
});

afterEach(function (): void {
    onSession('pgsql_session_a', fn (Connection $db): int => $db->table('tenants')->where('id', $this->raceTenant->id)->delete());
    DB::purge('pgsql_session_a');
    DB::purge('pgsql_session_b');
});

test('BR-C03 a second session waits on the order lock while the first collects the balance, then finds nothing left', function (): void {
    $a = DB::connection('pgsql_session_a');
    $a->beginTransaction();
    onSession('pgsql_session_a', fn () => app(RecordCollection::class)->handle($this->order, new CollectionData('500.00')));

    $blocked = onSession('pgsql_session_b', function (Connection $db): ?string {
        $db->beginTransaction();
        $db->statement("SET LOCAL lock_timeout = '200ms'");

        try {
            app(RecordCollection::class)->handle($this->order, new CollectionData('500.00'));

            return null;
        } catch (QueryException $e) {
            return $e->getCode();
        } finally {
            $db->rollBack();
        }
    });

    $a->commit();

    expect($blocked)->toBe('55P03')
        ->and(fn () => ($this->recordOn)('pgsql_session_b', '500.00'))->toThrow(ExceedsBalance::class)
        ->and(($this->collected)())->toBe('500.00');
});

test('BR-C03 two sessions that fit the balance together both record', function (): void {
    ($this->recordOn)('pgsql_session_a', '200.00');
    ($this->recordOn)('pgsql_session_b', '300.00');

    expect(($this->collected)())->toBe('500.00');
});
