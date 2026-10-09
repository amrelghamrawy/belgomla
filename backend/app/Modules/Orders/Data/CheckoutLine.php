<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\ResolvedPrice;

/**
 * A cart line that passed every checkout check (BR-PR06), with the price applied (BR-PR04) and the base units
 * to reserve (BR-INV03). Expects the SKU's `product` and `sellingUnit` loaded for the snapshot (BR-PR07).
 */
final readonly class CheckoutLine
{
    public function __construct(
        public Sku $sku,
        public int $quantity,
        public ResolvedPrice $price,
    ) {}

    public function quantityBase(): int
    {
        return $this->quantity * $this->sku->conversion_factor;
    }
}
