<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Services\NullOutstandingBalances;

test('until collections exist nobody owes anything', function (): void {
    expect(app(OutstandingBalances::class))->toBeInstanceOf(NullOutstandingBalances::class)
        ->and(app(OutstandingBalances::class)->forCustomers([1, 2]))->toBe([]);
});
