<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Customers\Services\NullOutstandingBalances;
use App\Modules\Customers\Services\NullRecentOrders;

test('until collections exist nobody owes anything', function (): void {
    expect(app(OutstandingBalances::class))->toBeInstanceOf(NullOutstandingBalances::class)
        ->and(app(OutstandingBalances::class)->forCustomers([1, 2]))->toBe([]);
});

test('until orders exist a customer has no recent orders', function (): void {
    expect(app(RecentOrders::class))->toBeInstanceOf(NullRecentOrders::class)
        ->and(app(RecentOrders::class)->forCustomer(1))->toBe([]);
});
