<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryPath;
use Illuminate\Support\Facades\DB;

/**
 * Renames and/or moves a category. A move rewrites the materialized path of the whole subtree in one
 * statement; moving under itself or a descendant throws CategoryCycle (BR-P06). Owns its transaction.
 * The slug is kept on rename so shared links keep working.
 */
final readonly class UpdateCategory
{
    public function __construct(
        private AuditLogger $audit,
        private CategoryPath $paths,
    ) {}

    public function handle(Category $category, CategoryData $data): Category
    {
        $parent = $this->parent($data->parentId);
        $old = $this->snapshot($category);

        return DB::transaction(function () use ($category, $data, $parent, $old): Category {
            if ($category->parent_id !== $data->parentId) {
                $this->rewriteSubtree($category->path, $this->paths->under($category, $parent));
                $category->parent_id = $data->parentId;
            }

            $category->name = $data->name;
            $category->sort_order = $data->sortOrder ?? $category->sort_order;
            $category->save();
            $category->refresh();

            $this->audit->log('category.updated', $category, $old, $this->snapshot($category));

            return $category;
        });
    }

    private function parent(?int $parentId): ?Category
    {
        if ($parentId === null) {
            return null;
        }

        return Category::query()->find($parentId) ?? throw InvalidCatalogInput::unknownReference('parent_id');
    }

    /** Paths only contain digits and slashes, so the new prefix can be inlined safely. */
    private function rewriteSubtree(string $from, string $to): void
    {
        $offset = mb_strlen($from) + 1;

        Category::query()
            ->where('path', 'like', $from.'%')
            ->update(['path' => DB::raw("'".preg_replace('/[^0-9\/]/', '', $to)."' || substr(path, {$offset})")]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Category $category): array
    {
        return ['name' => $category->name, 'parent_id' => $category->parent_id, 'sort_order' => $category->sort_order, 'path' => $category->path];
    }
}
