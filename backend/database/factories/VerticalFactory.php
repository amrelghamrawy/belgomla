<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vertical> */
final class VerticalFactory extends Factory
{
    protected $model = Vertical::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'vertical-'.fake()->unique()->numberBetween(1, 9_999_999),
            'name' => 'نشاط '.fake()->unique()->numberBetween(1, 9_999_999),
            'status' => VerticalStatus::Active,
            'sort_order' => 10,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => VerticalStatus::Archived]);
    }
}
