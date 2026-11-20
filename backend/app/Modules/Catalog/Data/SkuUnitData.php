<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Pricing\Data\SkuPricingData;

/**
 * One unit a SKU is sold in (V2 Task 13, D91): `id` null = a new unit (or one archived before with the same `unit_id`,
 * which comes back), `conversionFactor` base units per unit (BR-U03), its price set (BR-U04). Built by SkuData.
 */
final readonly class SkuUnitData
{
    public function __construct(
        public ?int $id,
        public int $unitId,
        public int $conversionFactor,
        public bool $isDefault,
        public SkuPricingData $pricing,
    ) {}

    /**
     * Column => value for the sku_units table, prices excluded (UpdateSkuPricing writes them).
     *
     * @return array{unit_id: int, conversion_factor: int, is_default: bool, sort_order: int, status: string}
     */
    public function attributes(int $sortOrder): array
    {
        return [
            'unit_id' => $this->unitId,
            'conversion_factor' => $this->conversionFactor,
            'is_default' => $this->isDefault,
            'sort_order' => $sortOrder,
            'status' => 'active',
        ];
    }
}
