<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryPath;
use App\Modules\Catalog\Services\SectionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Renames, re-sections, re-icons and/or moves a category. A move rewrites the materialized path of the whole subtree
 * in one statement; moving under itself or a descendant throws CategoryCycle (BR-P06). The section follows
 * the same rule (D68, D109): under a parent it is the parent's, as a root it is the active one sent (or kept), and a
 * change is written to the whole subtree. Owns its transaction. The slug is kept on rename so shared
 * links keep working.
 */
final readonly class UpdateCategory
{
    public function __construct(
        private AuditLogger $audit,
        private CategoryPath $paths,
        private SectionResolver $sections,
    ) {}

    public function handle(Category $category, CategoryData $data): Category
    {
        $parent = $this->parent($data->parentId);
        $sectionId = $parent instanceof Category
            ? $parent->section_id
            : ($this->sections->chosen($data->sectionId, $data->kind)->id ?? $category->section_id);
        $old = $this->snapshot($category);

        return DB::transaction(function () use ($category, $data, $parent, $sectionId, $old): Category {
            $moved = $category->parent_id !== $data->parentId;

            if ($moved || $sectionId !== $category->section_id) {
                $this->rewriteSubtree($category->path, $moved ? $this->paths->under($category, $parent) : null, $sectionId);
                $category->parent_id = $data->parentId;
                $category->section_id = $sectionId;
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
    private function rewriteSubtree(string $from, ?string $to, int $sectionId): void
    {
        $values = ['section_id' => $sectionId];

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
            'section_id' => $category->section_id,
            'icon' => $category->icon?->value,
            'sort_order' => $category->sort_order,
            'path' => $category->path,
        ];
    }
}
