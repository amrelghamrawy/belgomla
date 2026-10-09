<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

/**
 * The outcome of BR-PR04 for one SKU and quantity: the applied unit price, the tier it came from (null
 * = base price), the discount off the cover price shown next to it, and `unitPrice × quantity`.
 * Money and percents are 2-decimal strings. Cart display and checkout (BR-PR06) snapshot this.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public string $unitPrice,
        public ?int $tierMinQuantity,
        public ?string $discountPercent,
        public ?string $coverPrice,
        public string $lineTotal,
    ) {}
}
