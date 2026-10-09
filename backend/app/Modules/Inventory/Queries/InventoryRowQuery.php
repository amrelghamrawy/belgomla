<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Catalog\Models\Sku;

/**
 * One SKU's stock for the adjust form (08-api-contract.md §7: show `min_allowed` before adjusting). The
 * SKU comes from route binding, already scoped to the tenant (BR-T04).
 */
final readonly class InventoryRowQuery
{
    public function get(Sku $sku): Sku
    {
        return $sku->load(['product', 'inventory']);
    }
}
