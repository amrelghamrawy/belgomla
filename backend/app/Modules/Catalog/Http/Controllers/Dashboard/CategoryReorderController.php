<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\ReorderCategories;
use App\Modules\Catalog\Http\Requests\ReorderCategoriesRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Queries\CategoryTreeQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CategoryReorderController
{
    /** POST /api/dashboard/categories/reorder — returns the reordered tree. */
    public function store(ReorderCategoriesRequest $request, ReorderCategories $reorder, CategoryTreeQuery $tree): AnonymousResourceCollection
    {
        $reorder->handle($request->positions());

        return CategoryResource::collection($tree->forDashboard());
    }
}
