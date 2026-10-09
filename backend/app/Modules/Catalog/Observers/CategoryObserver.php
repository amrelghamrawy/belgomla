<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\SlugGenerator;

/**
 * Invariants only: the slug on create and the materialized path once the id is known.
 * Moving a category (path rebuild of the subtree) is done explicitly by CategoryPath in UpdateCategory.
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
    }

    public function created(Category $category): void
    {
        $parentPath = $category->parent_id === null
            ? '/'
            : Category::query()->whereKey($category->parent_id)->value('path');

        $category->path = (is_string($parentPath) ? $parentPath : '/').$category->id.'/';
        $category->saveQuietly();
    }
}
