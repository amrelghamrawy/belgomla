<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
final class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'وحدة '.fake()->unique()->numberBetween(1, 9_999_999),
            'status' => ArchiveStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
