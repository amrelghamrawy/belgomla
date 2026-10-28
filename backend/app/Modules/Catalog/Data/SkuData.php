<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Pricing\Data\SkuPricingData;
use InvalidArgumentException;

/**
 * The SKU of a product (BR-P02) and the units it is sold in (BR-U02, V2 Task 13, D91), in the order given. Exactly one
 * unit is the default: the one flagged, else the first. Each unit's prices, MOQ and tiers (BR-PR01, BR-PR10, BR-MOQ01)
 * are Pricing's: they travel as SkuPricingData and are written by UpdateSkuPricing.
 */
final readonly class SkuData
{
    /**
     * @param  non-empty-list<SkuUnitData>  $units
     */
    public function __construct(
        public string $code,
        public int $baseUnitId,
        public array $units,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): self
    {
        $inputs = $request->skuUnits();
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

        return new self(
            code: $request->skuCode(),
            baseUnitId: $request->skuInt('base_unit_id'),
            units: $units,
        );
    }

    /**
     * Column => value for the skus table; the units are written apart.
     *
     * @return array{code: string, base_unit_id: int}
     */
    public function attributes(): array
    {
        return [
            'code' => $this->code,
            'base_unit_id' => $this->baseUnitId,
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
