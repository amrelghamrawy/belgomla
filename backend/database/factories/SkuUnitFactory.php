<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\SkuUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A further unit of a SKU (D91), not its default: a SKU made by SkuFactory already has its default unit.
 *
 * @extends Factory<SkuUnit>
 */
final class SkuUnitFactory extends Factory
{
    protected $model = SkuUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_id' => SkuFactory::new(),
            'unit_id' => UnitFactory::new(),
            'conversion_factor' => 12,
            'base_price' => '1100.00',
            'base_price_percent' => null,
            'cover_price' => null,
            'moq' => 1,
            'is_default' => false,
            'sort_order' => 1,
            'status' => ArchiveStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
