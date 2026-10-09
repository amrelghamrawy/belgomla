<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Http\Requests\StoreCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use Illuminate\Http\UploadedFile;

/** Create or replace a category. `sortOrder` null = keep (update) or 0 (create). */
final readonly class CategoryData
{
    public function __construct(
        public string $name,
        public ?int $parentId,
        public ?int $sortOrder = null,
        public ?UploadedFile $image = null,
    ) {}

    public static function fromRequest(StoreCategoryRequest|UpdateCategoryRequest $request): self
    {
        return new self(
            name: $request->name(),
            parentId: $request->parentId(),
            sortOrder: $request->sortOrder(),
            image: $request instanceof StoreCategoryRequest ? $request->image() : null,
        );
    }
}
