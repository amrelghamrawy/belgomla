<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductFieldDefinition> */
final class ProductFieldDefinitionFactory extends Factory
{
    protected $model = ProductFieldDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'field_'.fake()->unique()->numberBetween(1, 9_999_999),
            'label' => 'حقل',
            'type' => FieldType::Text,
            'is_required' => false,
            'show_on_storefront' => true,
            'is_filterable' => false,
            'filter_order' => 0,
            'sort_order' => 0,
            'status' => ArchiveStatus::Active,
        ];
    }

    public function select(): static
    {
        return $this->state(['type' => FieldType::Select]);
    }

    public function number(): static
    {
        return $this->state(['type' => FieldType::Number]);
    }

    public function boolean(): static
    {
        return $this->state(['type' => FieldType::Boolean]);
    }

    public function filterable(): static
    {
        return $this->state(['is_filterable' => true]);
    }

    public function forKind(CategoryKind $kind): static
    {
        return $this->state(['kind' => $kind]);
    }

    public function required(): static
    {
        return $this->state(['is_required' => true]);
    }

    public function hidden(): static
    {
        return $this->state(['show_on_storefront' => false]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
