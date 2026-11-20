<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Sku;

/**
 * BR-INV02, BR-P15 (V2 Task 15, D93): a bundle has no stock of its own. The bundles that can be made now are the
 * minimum over its components of `floor(available base units / (quantity × the component unit's factor))`; a component
 * that can no longer be ordered (product not active, SKU inactive, unit archived) or is out of stock makes it 0, and is
 * what the storefront names as missing. A bundle without components is never available.
 *
 * Pure over loaded relations: expects the SKU's `bundleItems` with `componentUnit.sku.product` and
 * `componentUnit.sku.inventory` (RELATIONS, prefixed by the caller's path to the SKU).
 */
final readonly class BundleAvailability
{
    /** What `available()` reads, relative to the bundle SKU. */
    public const array RELATIONS = [
        'bundleItems.componentUnit.unit',
        'bundleItems.componentUnit.tiers',
        'bundleItems.componentUnit.sku.product',
        'bundleItems.componentUnit.sku.inventory',
    ];

    public function available(Sku $bundle): int
    {
        $items = $bundle->bundleItems;

        if ($items->isEmpty()) {
            return 0;
        }

        $allowed = array_values($items->map(fn (BundleItem $item): int => $this->allows($item))->all());

        return $allowed === [] ? 0 : min($allowed);
    }

    /** The bundles one component allows on its own: 0 when it cannot be ordered or is out of stock. */
    public function allows(BundleItem $item): int
    {
        if (! $this->orderable($item)) {
            return 0;
        }

        $available = $item->componentUnit->sku->inventory->available ?? 0;

        return intdiv(max(0, $available), max(1, $item->baseQuantity()));
    }

    /** The component is a unit still sold, of an active SKU of an active product (BR-P04, BR-P14, D91). */
    public function orderable(BundleItem $item): bool
    {
        $unit = $item->componentUnit;

        return $unit->sku->product->status === ProductStatus::Active && $unit->sku->isActive() && $unit->isActive();
    }
}
