<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\CreateCategory;
use App\Modules\Catalog\Actions\UpdateCategory;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Http\Requests\ListCategoriesRequest;
use App\Modules\Catalog\Http\Requests\StoreCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Queries\CategoryTreeQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CategoryController
{
    /** GET /api/dashboard/categories?status= */
    public function index(ListCategoriesRequest $request, CategoryTreeQuery $tree): AnonymousResourceCollection
    {
        return CategoryResource::collection($tree->forDashboard($request->status()));
    }

    /** POST /api/dashboard/categories */
    public function store(StoreCategoryRequest $request, CreateCategory $create): JsonResponse
    {
        $category = $create->handle(CategoryData::fromRequest($request));

        return new CategoryResource($category)->response()->setStatusCode(201);
    }

    /** PUT /api/dashboard/categories/{category} */
    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): CategoryResource
    {
        return new CategoryResource($update->handle($category, CategoryData::fromRequest($request)));
    }
}
