<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Queries\CategoryTreeQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CategoryController
{
    /** GET /api/store/categories — the active tree (BR-P06). */
    public function index(CategoryTreeQuery $tree): AnonymousResourceCollection
    {
        return CategoryResource::collection($tree->forStore());
    }
}
