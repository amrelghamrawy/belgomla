<?php

declare(strict_types=1);

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Services\NullOutstandingBalances;

test('collections fill the outstanding balances of customers', function (): void {
    expect(app(OutstandingBalances::class))->toBeInstanceOf(OutstandingByCustomerQuery::class);
});

test('without collections nobody owes anything', function (): void {
    expect(new NullOutstandingBalances()->forCustomers([1, 2]))->toBe([]);
});
