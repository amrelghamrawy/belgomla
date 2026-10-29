<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\GenerateGradeSet;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\GenerateBundleRequest;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Queries\ProductDetailQuery;
use Illuminate\Http\JsonResponse;

final readonly class BundleGenerateController
{
    /** POST /api/dashboard/bundles/generate: a draft (inactive) grade set from a product filter (D93). */
    public function store(GenerateBundleRequest $request, GenerateGradeSet $generate, ProductDetailQuery $detail): JsonResponse
    {
        $product = $generate->handle(ProductFilterData::fromRequest($request), $request->actorId());

        return new DashboardProductResource($detail->load($product))->response()->setStatusCode(201);
    }
}
