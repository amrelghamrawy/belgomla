<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Exceptions\CostingRefused;

/**
 * D96 a: base units in one of the SKU unit a cost or a receipt is typed in (any unit of this SKU, archived too); the
 * base unit itself (1) when none is named. A unit of another SKU or store is `422` on `sku_unit_id`.
 */
final readonly class SkuUnitFactorQuery
{
    public function get(Sku $sku, ?int $skuUnitId): int
    {
        if ($skuUnitId === null) {
            return 1;
        }

        $factor = $sku->units()->whereKey($skuUnitId)->value('conversion_factor');

        return is_numeric($factor) ? (int) $factor : throw CostingRefused::unknownUnit();
    }
}
