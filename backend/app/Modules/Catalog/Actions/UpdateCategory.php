<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryPath;
use Illuminate\Support\Facades\DB;

/**
 * Renames, re-kinds, re-icons and/or moves a category. A move rewrites the materialized path of the whole subtree
 * in one statement; moving under itself or a descendant throws CategoryCycle (BR-P06). The kind follows
 * the same rule (D68): under a parent it is the parent's, as a root it is the one sent (or kept), and a
 * change is written to the whole subtree. Owns its transaction. The slug is kept on rename so shared
 * links keep working.
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
        $kind = $parent instanceof Category ? $parent->kind : ($data->kind ?? $category->kind);
        $old = $this->snapshot($category);

        return DB::transaction(function () use ($category, $data, $parent, $kind, $old): Category {
            $moved = $category->parent_id !== $data->parentId;

            if ($moved || $kind !== $category->kind) {
                $this->rewriteSubtree($category->path, $moved ? $this->paths->under($category, $parent) : null, $kind);
                $category->parent_id = $data->parentId;
                $category->kind = $kind;
            }

            $category->name = $data->name;
            $category->sort_order = $data->sortOrder ?? $category->sort_order;
            $category->icon = $data->iconGiven ? $data->icon : $category->icon;
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
    private function rewriteSubtree(string $from, ?string $to, CategoryKind $kind): void
    {
        $values = ['kind' => $kind->value];

        if ($to !== null) {
            $offset = mb_strlen($from) + 1;
            $values['path'] = DB::raw("'".preg_replace('/[^0-9\/]/', '', $to)."' || substr(path, {$offset})");
        }

        Category::query()->where('path', 'like', $from.'%')->update($values);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Category $category): array
    {
        return [
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'kind' => $category->kind->value,
            'icon' => $category->icon?->value,
            'sort_order' => $category->sort_order,
            'path' => $category->path,
        ];
    }
}
