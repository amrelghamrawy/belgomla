<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => 'قسم '.fake()->unique()->numberBetween(1, 9_999_999),
            'sort_order' => 0,
            'status' => ArchiveStatus::Active,
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->id]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
