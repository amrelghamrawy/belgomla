<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\VerticalFieldTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerticalFieldTemplate> */
final class VerticalFieldTemplateFactory extends Factory
{
    protected $model = VerticalFieldTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vertical_id' => VerticalFactory::new(),
            'vertical_section_id' => null,
            'key' => 'field_'.fake()->unique()->numberBetween(1, 9_999_999),
            'label' => 'حقل '.fake()->unique()->numberBetween(1, 9_999_999),
            'type' => FieldType::Text,
            'is_required' => false,
            'is_filterable' => false,
            'filter_order' => 0,
            'sort_order' => 0,
            'status' => VerticalStatus::Active,
        ];
    }

    public function select(): static
    {
        return $this->state(['type' => FieldType::Select, 'is_filterable' => true]);
    }

    public function archived(): static
    {
        return $this->state(['status' => VerticalStatus::Archived]);
    }
}
