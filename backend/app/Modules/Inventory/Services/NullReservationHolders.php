<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Contracts\ReservationHolders;

/** No orders exist before Task 6, so nothing holds a reservation by name. */
final readonly class NullReservationHolders implements ReservationHolders
{
    public function ordersHolding(Sku $sku): array
    {
        return [];
    }
}
