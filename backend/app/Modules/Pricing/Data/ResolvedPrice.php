<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Modules\Pricing\Enums\PriceSource;

/**
 * The outcome of BR-PR04 / BR-PR14 for one SKU, quantity and customer: the applied unit price, the tier it came from
 * (null = not a tier), the discount off the cover price shown next to it, `unitPrice × quantity`, and where the price
 * came from (`source`: base, tier, group or customer, D89). Money and percents are 2-decimal strings. Cart display and
 * checkout (BR-PR06) snapshot this.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public string $unitPrice,
        public ?int $tierMinQuantity,
        public ?string $discountPercent,
        public ?string $coverPrice,
        public string $lineTotal,
        public PriceSource $source = PriceSource::Base,
    ) {}
}
