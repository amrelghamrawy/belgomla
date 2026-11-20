<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Modules\Pricing\Enums\BundlePriceMode;

/**
 * How a bundle is priced (BR-P15, D93): `value` is the price in `fixed` mode, the % taken off the components' sum in
 * `percent_off_sum` mode (2-decimal strings). Built by Catalog's BundleData from the bundle payload.
 */
final readonly class BundlePricingData
{
    public function __construct(
        public BundlePriceMode $mode,
        public string $value,
    ) {}
}
