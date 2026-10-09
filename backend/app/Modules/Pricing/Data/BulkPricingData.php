<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Pricing\Http\Requests\BulkPricingRequest;

/**
 * BR-PR11: price every SKU matching a product filter as a % off its cover price, with tiers given as
 * percents too. `moq` null keeps each SKU's MOQ.
 */
final readonly class BulkPricingData
{
    /**
     * @param  list<TierData>  $tiers
     */
    public function __construct(
        public ProductFilterData $filter,
        public string $basePricePercent,
        public array $tiers = [],
        public ?int $moq = null,
    ) {}

    public static function fromRequest(BulkPricingRequest $request): self
    {
        return new self(
            filter: new ProductFilterData(...$request->productFilterInput()),
            basePricePercent: $request->basePricePercent(),
            tiers: $request->tierInput() ?? [],
            moq: $request->moq(),
        );
    }
}
