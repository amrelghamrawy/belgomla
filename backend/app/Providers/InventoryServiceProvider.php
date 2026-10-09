<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Inventory\Services\NullReservationHolders;
use Illuminate\Support\ServiceProvider;

/**
 * Inventory wiring: the orders holding a reservation (BR-INV08) come from Orders, which rebinds
 * ReservationHolders in Task 6; until then no order holds anything by name.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ReservationHolders::class => NullReservationHolders::class,
    ];
}
