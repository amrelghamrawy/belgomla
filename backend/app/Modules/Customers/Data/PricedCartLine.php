<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Pricing\Data\ResolvedPrice;

/**
 * One cart line priced now (BR-PR04), in its SKU unit (D91): quantity and MOQ in that unit, `available` in base units like the
 * storefront (D37). A pre-order line (BR-O14) has `available` 0 and the day its product is expected. Read model built by
 * CartPricer, not request input. `variant` is the SKU's variant label («أزرق / M», BR-P14), null without options.
 */
final readonly class PricedCartLine
{
    public function __construct(
        public int $skuId,
        public int $skuUnitId,
        public string $productName,
        public string $productSlug,
        public string $unit,
        public int $conversionFactor,
        public int $quantity,
        public ResolvedPrice $price,
        public int $moq,
        public int $available,
        public ?string $expectedAt = null,
        public ?string $variant = null,
    ) {}
}
