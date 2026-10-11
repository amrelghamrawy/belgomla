<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\SlugGenerator;

/**
 * Invariants only: the slug and the inherited kind on create, and the materialized path once the id is
 * known. Moving a category (path and kind rewrite of the subtree) is done explicitly by UpdateCategory.
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
            $category->kind = $this->parent($category)->kind;
        }
    }

    public function created(Category $category): void
    {
        $category->path = ($category->parent_id === null ? '/' : $this->parent($category)->path).$category->id.'/';
        $category->saveQuietly();
    }

    private function parent(Category $category): Category
    {
        return Category::query()->whereKey($category->parent_id)->firstOrFail(['id', 'path', 'kind']);
    }
}
