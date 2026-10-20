<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Pricing\Data\TierData;

/**
 * BR-O11: the merchant's order form as checkout would see it now (OrderQuoter): the validator's report and, for the
 * tier hint next to each line, every SKU's tiers keyed by sku_id. Shown by OrderQuoteResource.
 */
final readonly class OrderQuote
{
    /**
     * @param  array<int, list<TierData>>  $tiers
     */
    public function __construct(
        public CheckoutReport $report,
        public array $tiers,
    ) {}
}
