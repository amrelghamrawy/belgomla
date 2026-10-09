<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

/**
 * One quantity tier (BR-PR03) as entered: an absolute `unitPrice` or a `discountPercent` off the cover
 * price (BR-PR10). When only the percent is given the price is derived from it; when both are given
 * (a copy of stored tiers) both are kept as they are. Money and percents are 2-decimal strings.
 */
final readonly class TierData
{
    public function __construct(
        public int $minQuantity,
        public ?string $unitPrice = null,
        public ?string $discountPercent = null,
    ) {}
}
