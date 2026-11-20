<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\VerticalSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerticalSection> */
final class VerticalSectionFactory extends Factory
{
    protected $model = VerticalSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vertical_id' => VerticalFactory::new(),
            'key' => 'section-'.fake()->unique()->numberBetween(1, 9_999_999),
            'name' => 'قسم '.fake()->unique()->numberBetween(1, 9_999_999),
            'reference_price_label' => 'سعر القطاعي',
            'icon' => null,
            'status' => VerticalStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => VerticalStatus::Archived]);
    }
}
