<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The category tree in one query, assembled in memory: every node's `children` relation is set,
 * ordered by sort_order then id (BR-P06).
 */
final readonly class CategoryTreeQuery
{
    /**
     * Dashboard: every category, or only one status. A node whose parent is filtered out becomes a root.
     *
     * @return Collection<int, Category> roots
     */
    public function forDashboard(?ArchiveStatus $status = null): Collection
    {
        $categories = $this->ordered()
            ->when($status instanceof ArchiveStatus, fn (Builder $query): Builder => $query->where('status', $status))
            ->get();

        return $this->assemble($categories, keepOrphans: true);
    }

    /**
     * Store: active categories only; an archived category hides its whole subtree.
     *
     * @return Collection<int, Category> roots
     */
    public function forStore(): Collection
    {
        return $this->assemble($this->ordered()->where('status', ArchiveStatus::Active)->get(), keepOrphans: false);
    }

    /**
     * @return Builder<Category>
     */
    private function ordered(): Builder
    {
        return Category::query()->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function assemble(Collection $categories, bool $keepOrphans): Collection
    {
        $byId = $categories->keyBy('id');
        $children = $categories->groupBy('parent_id');

        foreach ($categories as $category) {
            $category->setRelation('children', new Collection($children->get($category->id)?->all() ?? []));
        }

        return $categories
            ->filter(fn (Category $category): bool => $category->parent_id === null || ($keepOrphans && ! $byId->has($category->parent_id)))
            ->values();
    }
}
