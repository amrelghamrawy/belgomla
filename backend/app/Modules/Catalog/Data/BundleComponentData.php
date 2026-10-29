<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

/** One component of a bundle (BR-P15, D93): `quantity` of the SKU unit `skuUnitId` go into one bundle. */
final readonly class BundleComponentData
{
    public function __construct(
        public int $skuUnitId,
        public int $quantity,
    ) {}
}
