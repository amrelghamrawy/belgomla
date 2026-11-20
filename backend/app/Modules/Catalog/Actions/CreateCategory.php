<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\SectionResolver;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a category at any depth (BR-P06). Slug and path are set by CategoryObserver, which also
 * replaces the section of a child with its parent's (D68). A root goes to the chosen active section of the store, or
 * to its fallback «عام» (D109 c). Owns its transaction.
 */
final readonly class CreateCategory
{
    public function __construct(
        private AuditLogger $audit,
        private TenantContext $context,
        private SectionResolver $sections,
    ) {}

    public function handle(CategoryData $data): Category
    {
        if ($data->parentId !== null && Category::query()->whereKey($data->parentId)->doesntExist()) {
            throw InvalidCatalogInput::unknownReference('parent_id');
        }

        $section = $this->sections->chosen($data->sectionId, $data->kind) ?? $this->sections->fallback();

        return DB::transaction(function () use ($data, $section): Category {
            $category = Category::query()->create([
                'parent_id' => $data->parentId,
                'name' => $data->name,
                'section_id' => $section->id,
                'icon' => $data->icon,
                'sort_order' => $data->sortOrder ?? 0,
            ]);

            if ($data->image instanceof UploadedFile) {
                $category->update(['image_path' => $this->store($data->image)]);
            }

            $this->audit->log('category.created', $category, null, $category->only(['name', 'parent_id', 'section_id', 'icon', 'sort_order', 'image_path']));

            return $category->refresh();
        });
    }

    private function store(UploadedFile $image): string
    {
        $path = $image->store("tenants/{$this->context->id()}/categories", 'public');

        if ($path === false) {
            throw new RuntimeException('Could not store the category image.');
        }

        return $path;
    }
}
