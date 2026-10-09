<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Models\Sku;

/**
 * BR-INV08: the open orders holding a SKU's reservation, listed when an adjustment would go below it.
 * Implemented by Orders (OrdersHoldingSku, bound in OrdersServiceProvider) so Inventory never depends on Orders.
 */
interface ReservationHolders
{
    /**
     * @return list<array{number: int, status: string, quantity_base: int}>
     */
    public function ordersHolding(Sku $sku): array;
}
