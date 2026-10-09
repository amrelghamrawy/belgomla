<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;

/**
 * The single V1 SKU of a product (BR-P02, BR-U02, BR-PR01, BR-PR10, BR-MOQ01).
 * Money values are normalised 2-decimal strings. Tiers arrive with Task 2.
 */
final readonly class SkuData
{
    public function __construct(
        public string $code,
        public int $sellingUnitId,
        public int $baseUnitId,
        public int $conversionFactor,
        public string $basePrice,
        public ?string $coverPrice = null,
        public ?string $basePricePercent = null,
        public int $moq = 1,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): self
    {
        return new self(
            code: $request->skuCode(),
            sellingUnitId: $request->skuInt('selling_unit_id'),
            baseUnitId: $request->skuInt('base_unit_id'),
            conversionFactor: $request->skuInt('conversion_factor'),
            basePrice: $request->skuMoney('base_price') ?? '0.00',
            coverPrice: $request->skuMoney('cover_price'),
            basePricePercent: $request->skuMoney('base_price_percent'),
            moq: $request->skuInt('moq', 1),
        );
    }

    /**
     * Column => value for the skus table.
     *
     * @return array<string, int|string|null>
     */
    public function attributes(): array
    {
        return [
            'code' => $this->code,
            'selling_unit_id' => $this->sellingUnitId,
            'base_unit_id' => $this->baseUnitId,
            'conversion_factor' => $this->conversionFactor,
            'cover_price' => $this->coverPrice,
            'base_price' => $this->basePrice,
            'base_price_percent' => $this->basePricePercent,
            'moq' => $this->moq,
        ];
    }
}
