<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;

/**
 * One component of a checked bundle line (BR-P15, D93): its SKU unit, how many go into one bundle (`perBundle`), and
 * the price this customer would pay for that many on their own (`weight`, the PriceResolver's line total at that
 * quantity), by which the bundle's price is split over its components (OrderSnapshots).
 */
final readonly class CheckoutComponent
{
    public Sku $sku;

    public function __construct(
        public SkuUnit $unit,
        public int $perBundle,
        public string $weight,
    ) {
        $this->sku = $unit->sku;
    }
}
