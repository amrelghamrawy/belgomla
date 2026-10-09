<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Pricing\Data\ResolvedPrice;

/**
 * One cart line priced now (BR-PR04): quantity and MOQ in selling units, `available` in base units like the
 * storefront (D37). Read model built by CartPricer, not request input.
 */
final readonly class PricedCartLine
{
    public function __construct(
        public int $skuId,
        public string $productName,
        public string $unit,
        public int $quantity,
        public ResolvedPrice $price,
        public int $moq,
        public int $available,
    ) {}
}
