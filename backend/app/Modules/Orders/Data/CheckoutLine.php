<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\ResolvedPrice;

/**
 * A cart line that passed every checkout check (BR-PR06), with the price applied (BR-PR04) and the base units
 * to reserve (BR-INV03): quantity × the factor of its unit (BR-U03, D91). Expects the unit's `unit`, `sku` and
 * `sku.product` loaded for the snapshot (BR-PR07). A bundle line (BR-P15, D93) carries its `components`, which hold the
 * stock and share its price; a simple line has none.
 */
final readonly class CheckoutLine
{
    public Sku $sku;

    /**
     * @param  list<CheckoutComponent>  $components
     */
    public function __construct(
        public SkuUnit $unit,
        public int $quantity,
        public ResolvedPrice $price,
        public array $components = [],
    ) {
        $this->sku = $unit->sku;
    }

    public function quantityBase(): int
    {
        return $this->quantity * $this->unit->conversion_factor;
    }

    public function isBundle(): bool
    {
        return $this->components !== [];
    }
}
