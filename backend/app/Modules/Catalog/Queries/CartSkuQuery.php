<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\SkuUnit;
use Illuminate\Database\Eloquent\Collection;

/**
 * A SKU unit by id with what an order line needs to check and price it (BR-CU06, BR-PR06; V2 Task 13, D91): its SKU's
 * product (status, name), stock and default unit (the pre-order cap is in it), the unit's name and its tiers. Archived units are found too (an order line may still
 * point at one); the checks refuse to order them. A bundle's unit brings its components with what checking and splitting
 * them needs (BR-P15, D93). Tenant-scoped, so another tenant's id is not found (404, BR-T04).
 */
final readonly class CartSkuQuery
{
    /** The relations of a SKU unit that an order line reads. */
    public const array RELATIONS = [
        'sku.product', 'sku.inventory', 'sku.defaultUnit', 'unit', 'tiers',
        'sku.bundleItems.componentUnit.unit', 'sku.bundleItems.componentUnit.tiers', 'sku.bundleItems.componentUnit.sku.product', 'sku.bundleItems.componentUnit.sku.inventory', // D93
    ];

    public function find(int $skuUnitId): SkuUnit
    {
        return SkuUnit::query()->with(self::RELATIONS)->findOrFail($skuUnitId);
    }

    /** The default unit of a SKU (a line that names the SKU only). */
    public function findDefault(int $skuId): SkuUnit
    {
        return SkuUnit::query()->with(self::RELATIONS)->where('sku_id', $skuId)->where('is_default', true)->firstOrFail();
    }

    /**
     * Several units at once (a merchant's order form, BR-O11), keyed by id; unknown and other tenants' ids are left out.
     *
     * @param  list<int>  $skuUnitIds
     * @return Collection<int, SkuUnit>
     */
    public function findMany(array $skuUnitIds): Collection
    {
        return SkuUnit::query()->with(self::RELATIONS)->whereIn('id', $skuUnitIds)->get()->keyBy('id');
    }
}
