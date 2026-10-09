<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductFieldOption> */
final class ProductFieldOptionFactory extends Factory
{
    protected $model = ProductFieldOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'definition_id' => ProductFieldDefinitionFactory::new()->select(),
            'label' => 'خيار '.fake()->unique()->numberBetween(1, 9_999_999),
            'sort_order' => 0,
            'status' => ArchiveStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
