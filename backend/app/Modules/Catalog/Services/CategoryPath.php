<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Exceptions\CategoryCycle;
use App\Modules\Catalog\Models\Category;

/**
 * Materialized-path arithmetic for the category tree (Tech §9b). Pure computation on loaded models;
 * the subtree rewrite itself is done by UpdateCategory.
 */
final readonly class CategoryPath
{
    /**
     * The path the category would have under $parent. Throws when $parent is the category itself
     * or one of its descendants (BR-P06).
     */
    public function under(Category $category, ?Category $parent): string
    {
        if ($parent instanceof Category && $this->isWithin($parent, $category)) {
            throw CategoryCycle::make();
        }

        return ($parent instanceof Category ? $parent->path : '/').$category->id.'/';
    }

    /** True when $node is $ancestor or sits anywhere below it. */
    public function isWithin(Category $node, Category $ancestor): bool
    {
        return str_contains($node->path, "/{$ancestor->id}/");
    }

    /**
     * @return list<int> ancestor ids from the root down, excluding the category itself
     */
    public function ancestorIds(Category $category): array
    {
        $ids = array_map(intval(...), array_values(array_filter(explode('/', $category->path), fn (string $part): bool => $part !== '')));

        return array_values(array_filter($ids, fn (int $id): bool => $id !== $category->id));
    }
}
