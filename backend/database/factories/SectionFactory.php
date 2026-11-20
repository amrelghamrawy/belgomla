<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A store-made section. The sections a store gets from its vertical, and its fallback «عام», are created with the
 * tenant (TenantFactory, CreateTenant); find them with `Section::query()->where('key', 'books')`.
 *
 * @extends Factory<Section>
 */
final class SectionFactory extends Factory
{
    protected $model = Section::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 9_999_999);

        return [
            'vertical_section_id' => null,
            'key' => "section-{$n}",
            'name' => "قسم {$n}",
            'reference_price_label' => 'سعر القطاعي',
            'icon' => null,
            'is_fallback' => false,
            'status' => ArchiveStatus::Active,
            'sort_order' => 10,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
