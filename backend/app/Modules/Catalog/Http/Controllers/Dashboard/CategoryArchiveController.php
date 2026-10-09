<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\ArchiveCategory;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;

final readonly class CategoryArchiveController
{
    /** POST /api/dashboard/categories/{category}/archive */
    public function store(Category $category, ArchiveCategory $archive): CategoryResource
    {
        return new CategoryResource($archive->handle($category));
    }
}
