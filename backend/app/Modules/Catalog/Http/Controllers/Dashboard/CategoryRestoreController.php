<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\RestoreCategory;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;

final readonly class CategoryRestoreController
{
    /** POST /api/dashboard/categories/{category}/restore */
    public function store(Category $category, RestoreCategory $restore): CategoryResource
    {
        return new CategoryResource($restore->handle($category));
    }
}
