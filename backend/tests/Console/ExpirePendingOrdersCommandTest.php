<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| BR-INV07, D81: `orders:expire-pending` walks every store with the setting on, inside its tenant context.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->freezeSecond();

    $this->orderIn = function (Tenant $tenant, int $hoursAgo): Order {
        $order = $this->placeOrder($this->makeCustomer(tenant: $tenant), [$this->orderableSku(tenant: $tenant)->id => 6]);
        $order->forceFill(['placed_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $order;
    };
    $this->statusOf = fn (Order $order): OrderStatus => Order::withoutTenantScope()->findOrFail($order->id)->status;
});

test('BR-INV07 only the orders past the store\'s expiry are cancelled, and the counts are printed', function (): void {
    $this->tenant->settings->update(['pending_expiry_hours' => 24]);
    $old = ($this->orderIn)($this->tenant, 30);
    $fresh = ($this->orderIn)($this->tenant, 2);

    $this->artisan('orders:expire-pending')->expectsOutput('expired 1, skipped 0, failed 0')->assertSuccessful();

    expect(($this->statusOf)($old))->toBe(OrderStatus::Cancelled)
        ->and(Order::withoutTenantScope()->findOrFail($old->id)->cancelled_by_type)->toBe(CancelledBy::System)
        ->and(($this->statusOf)($fresh))->toBe(OrderStatus::Pending);
});

test('D81 a store without the setting keeps its old pending orders', function (): void {
    $old = ($this->orderIn)($this->tenant, 500);

    $this->artisan('orders:expire-pending')->expectsOutput('expired 0, skipped 0, failed 0')->assertSuccessful();

    expect(($this->statusOf)($old))->toBe(OrderStatus::Pending);
});

test('BR-T04 one store\'s setting never expires another store\'s orders', function (): void {
    $this->tenant->settings->update(['pending_expiry_hours' => 24]);
    $other = $this->otherTenant();
    $other->settings()->update(['pending_expiry_hours' => 100]);
    $mine = ($this->orderIn)($this->tenant, 30);
    $theirs = ($this->orderIn)($other, 30);

    $this->artisan('orders:expire-pending')->assertSuccessful();

    expect(($this->statusOf)($mine))->toBe(OrderStatus::Cancelled)
        ->and(($this->statusOf)($theirs))->toBe(OrderStatus::Pending);
});

test('a suspended store waits until it is active again', function (): void {
    $this->tenant->settings->update(['pending_expiry_hours' => 24]);
    $old = ($this->orderIn)($this->tenant, 30);
    $this->tenant->update(['status' => TenantStatus::Suspended]);

    $this->artisan('orders:expire-pending')->expectsOutput('expired 0, skipped 0, failed 0')->assertSuccessful();

    expect(($this->statusOf)($old))->toBe(OrderStatus::Pending);
});

test('BR-O03 an order with money collected is counted as skipped', function (): void {
    $this->tenant->settings->update(['pending_expiry_hours' => 24]);
    $old = ($this->orderIn)($this->tenant, 30);
    $old->forceFill(['collected_amount' => '50.00'])->saveQuietly();

    $this->artisan('orders:expire-pending')->expectsOutput('expired 0, skipped 1, failed 0')->assertSuccessful();
});

test('one failing order is reported and the others still expire', function (): void {
    $this->tenant->settings->update(['pending_expiry_hours' => 24]);
    $broken = ($this->orderIn)($this->tenant, 40);
    $fine = ($this->orderIn)($this->tenant, 30);

    // A trigger refuses to cancel the first order, like a database error halfway through.
    DB::unprepared(<<<SQL
        CREATE FUNCTION refuse_cancel() RETURNS trigger AS \$\$
        BEGIN
            IF NEW.id = {$broken->id} AND NEW.status = 'cancelled' THEN RAISE EXCEPTION 'refused'; END IF;
            RETURN NEW;
        END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER refuse_cancel BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION refuse_cancel();
        SQL);

    $this->artisan('orders:expire-pending')->expectsOutput('expired 1, skipped 0, failed 1')->assertFailed();

    expect(($this->statusOf)($broken))->toBe(OrderStatus::Pending)
        ->and(($this->statusOf)($fine))->toBe(OrderStatus::Cancelled);
});
