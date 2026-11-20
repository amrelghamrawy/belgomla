<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Section;
use App\Modules\Catalog\Services\SlugGenerator;

/**
 * Invariants only: the slug and the section on create (the parent's, or the fallback for a root without one), and the materialized path once the id is
 * known. Moving a category (path and section rewrite of the subtree) is done explicitly by UpdateCategory.
 */
final readonly class CategoryObserver
{
    public const int SLUG_LENGTH = 120;

    public function __construct(private SlugGenerator $slugs) {}

    public function creating(Category $category): void
    {
        if (($category->getAttributes()['slug'] ?? '') === '') {
            $category->slug = $this->slugs->unique(Category::class, $category->name, self::SLUG_LENGTH);
        }

        if ($category->parent_id !== null) {
            $category->section_id = $this->parent($category)->section_id;
        } elseif (($category->getAttributes()['section_id'] ?? null) === null) {
            $fallback = $this->fallbackSectionId();

            if ($fallback !== null) {
                $category->section_id = $fallback;
            }
        }
    }

    public function created(Category $category): void
    {
        $category->path = ($category->parent_id === null ? '/' : $this->parent($category)->path).$category->id.'/';
        $category->saveQuietly();
    }

    /** D109 c: a root with no section goes to the store's fallback «عام»; outside a store there is none (BR-T03). */
    private function fallbackSectionId(): ?int
    {
        $id = Section::query()->where('is_fallback', true)->value('id');

        return is_int($id) ? $id : null;
    }

    private function parent(Category $category): Category
    {
        return Category::query()->whereKey($category->parent_id)->firstOrFail(['id', 'path', 'section_id']);
    }
}
