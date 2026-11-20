<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Section;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Categories of the store in context, in its fallback section «عام» unless `inSection()` names another (D109); the
 * store's sections exist from TenantFactory / CreateTenant.
 *
 * @extends Factory<Category>
 */
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
            'section_id' => fn (): ?int => $this->sectionIdInContext('general'),
            'status' => ArchiveStatus::Active,
        ];
    }

    /** The observer copies the parent's section, so the one set here is replaced. */
    public function childOf(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->id]);
    }

    /** A root in the store's section with this key: `books`, `stationery`, `toys`, `general` or a store-made one. */
    public function inSection(string $key): static
    {
        return $this->state(['section_id' => fn (): ?int => $this->sectionIdInContext($key)]);
    }

    public function books(): static
    {
        return $this->inSection('books');
    }

    /** The id of the section with this key in the store in context. */
    public static function sectionId(string $key): int
    {
        return Section::query()->where('key', $key)->firstOrFail()->id;
    }

    /** Without a store in context there is no section to look up: the model's own guard then refuses the row (BR-T03). */
    private function sectionIdInContext(string $key): ?int
    {
        return app(TenantContext::class)->has() ? self::sectionId($key) : null;
    }

    public function archived(): static
    {
        return $this->state(['status' => ArchiveStatus::Archived]);
    }
}
