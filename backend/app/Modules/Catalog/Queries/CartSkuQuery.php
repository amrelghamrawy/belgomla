<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Sku;

/**
 * A SKU by id with what the cart needs to check and price a line (BR-CU06, BR-PR06): its product (status,
 * name), selling unit, tiers and stock. Tenant-scoped, so another tenant's id is not found (404, BR-T04).
 */
final readonly class CartSkuQuery
{
    /** The relations of a SKU that a cart line reads. */
    public const array RELATIONS = ['product', 'sellingUnit', 'tiers', 'inventory'];

    public function find(int $skuId): Sku
    {
        return Sku::query()->with(self::RELATIONS)->findOrFail($skuId);
    }
}
