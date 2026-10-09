<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Pricing\Data\SkuPricingData;

/**
 * The single V1 SKU of a product (BR-P02, BR-U02). Its prices, MOQ and tiers (BR-PR01, BR-PR10,
 * BR-MOQ01) are Pricing's: they travel as SkuPricingData and are written by UpdateSkuPricing.
 */
final readonly class SkuData
{
    public function __construct(
        public string $code,
        public int $sellingUnitId,
        public int $baseUnitId,
        public int $conversionFactor,
        public SkuPricingData $pricing,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): self
    {
        return new self(
            code: $request->skuCode(),
            sellingUnitId: $request->skuInt('selling_unit_id'),
            baseUnitId: $request->skuInt('base_unit_id'),
            conversionFactor: $request->skuInt('conversion_factor'),
            pricing: new SkuPricingData(...$request->pricingInput()),
        );
    }

    /**
     * Column => value for the skus table, prices excluded.
     *
     * @return array<string, int|string>
     */
    public function attributes(): array
    {
        return [
            'code' => $this->code,
            'selling_unit_id' => $this->sellingUnitId,
            'base_unit_id' => $this->baseUnitId,
            'conversion_factor' => $this->conversionFactor,
        ];
    }
}
