<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a category at any depth (BR-P06). Slug and path are set by CategoryObserver, which also
 * replaces the kind of a child with its parent's (D68). Owns its transaction.
 */
final readonly class CreateCategory
{
    public function __construct(
        private AuditLogger $audit,
        private TenantContext $context,
    ) {}

    public function handle(CategoryData $data): Category
    {
        if ($data->parentId !== null && Category::query()->whereKey($data->parentId)->doesntExist()) {
            throw InvalidCatalogInput::unknownReference('parent_id');
        }

        return DB::transaction(function () use ($data): Category {
            $category = Category::query()->create([
                'parent_id' => $data->parentId,
                'name' => $data->name,
                'kind' => $data->kind ?? CategoryKind::General,
                'sort_order' => $data->sortOrder ?? 0,
            ]);

            if ($data->image instanceof UploadedFile) {
                $category->update(['image_path' => $this->store($data->image)]);
            }

            $this->audit->log('category.created', $category, null, $category->only(['name', 'parent_id', 'kind', 'sort_order', 'image_path']));

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
