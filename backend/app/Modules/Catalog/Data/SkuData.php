<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Pricing\Data\SkuPricingData;
use InvalidArgumentException;

/**
 * One SKU of a product (BR-P02): the only one of a product without options, or one variant (BR-P14, V2 Task 14) naming
 * one value of every option in `optionValues` (value texts, option order). `id` null = a new SKU, or the product's SKU
 * with the same code or combination. `openingStock` (base units) applies only when the SKU is created. `path` is where it
 * sits in the payload ("sku" or "skus.{i}") and prefixes its errors.
 *
 * The units it is sold in (BR-U02, V2 Task 13, D91) come in the order given; exactly one is the default: the one flagged,
 * else the first. Each unit's prices, MOQ and tiers (BR-PR01, BR-PR10, BR-MOQ01) are Pricing's: they travel as
 * SkuPricingData and are written by UpdateSkuPricing.
 */
final readonly class SkuData
{
    /**
     * @param  non-empty-list<SkuUnitData>  $units
     * @param  list<string>  $optionValues
     */
    public function __construct(
        public string $code,
        public int $baseUnitId,
        public array $units,
        public ?int $id = null,
        public SkuStatus $status = SkuStatus::Active,
        public array $optionValues = [],
        public int $openingStock = 0,
        public string $path = 'sku',
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request, string $path = 'sku'): self
    {
        $inputs = $request->skuUnits($path);
        $flagged = array_keys(array_filter(array_column($inputs, 'isDefault')));
        $default = $flagged[0] ?? 0;
        $units = [];

        foreach ($inputs as $index => $input) {
            $units[] = new SkuUnitData(
                id: $input['id'],
                unitId: $input['unitId'],
                conversionFactor: $input['conversionFactor'],
                isDefault: $index === $default,
                pricing: new SkuPricingData(...$input['pricing']),
            );
        }

        if ($units === []) {
            throw new InvalidArgumentException('A SKU is sold in at least one unit (the request rules require one).');
        }

        $legacy = $path === 'sku';

        return new self(
            code: $request->skuCode($path),
            baseUnitId: $request->skuInt('base_unit_id', 0, $path),
            units: $units,
            id: $legacy ? null : $request->skuId($path),
            status: $legacy ? SkuStatus::Active : $request->skuStatus($path),
            optionValues: $legacy ? [] : $request->skuOptionValues($path),
            openingStock: $legacy ? ($request instanceof StoreProductRequest ? $request->openingStock() : 0) : $request->skuInt('opening_stock', 0, $path),
            path: $path,
        );
    }

    /**
     * Column => value for the skus table; the units and option values are written apart.
     *
     * @return array{code: string, base_unit_id: int, status: SkuStatus, sort_order: int}
     */
    public function attributes(int $sortOrder = 0): array
    {
        return [
            'code' => $this->code,
            'base_unit_id' => $this->baseUnitId,
            'status' => $this->status,
            'sort_order' => $sortOrder,
        ];
    }

    public function defaultUnit(): SkuUnitData
    {
        foreach ($this->units as $unit) {
            if ($unit->isDefault) {
                return $unit;
            }
        }

        return $this->units[0];
    }
}
