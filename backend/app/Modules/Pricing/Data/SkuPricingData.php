<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Modules\Pricing\Http\Requests\UpdateSkuPricingRequest;

/**
 * The full price set of one SKU (BR-PR01, BR-PR10, BR-MOQ01): base price as an absolute amount or a
 * % off the cover price, MOQ and quantity tiers, all in the selling unit (BR-U04).
 *
 *  - `basePrice` null and `basePricePercent` set: the price is derived from the cover price.
 *  - both set: copied as they are (duplicate, recompute of an absolute price).
 *  - `tiers` null: the SKU keeps its current tiers; a list (even empty) replaces them.
 *  - `coverPriceGiven` false: the SKU keeps its current cover price.
 */
final readonly class SkuPricingData
{
    /**
     * @param  list<TierData>|null  $tiers
     */
    public function __construct(
        public ?string $basePrice,
        public ?string $basePricePercent = null,
        public int $moq = 1,
        public ?array $tiers = null,
        public ?string $coverPrice = null,
        public bool $coverPriceGiven = true,
    ) {}

    public static function fromRequest(UpdateSkuPricingRequest $request): self
    {
        return new self(...$request->pricingInput());
    }
}
