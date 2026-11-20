<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Pricing\Http\Requests\PreviewPromotionRequest;
use App\Modules\Pricing\Http\Resources\PromotionPreviewResource;
use App\Modules\Pricing\Queries\PromotionTargetsQuery;

final readonly class PromotionPreviewController
{
    /** POST /api/dashboard/promotions/preview — BR-PR15, D90 d, writes nothing */
    public function store(PreviewPromotionRequest $request, PromotionTargetsQuery $targets): PromotionPreviewResource
    {
        return new PromotionPreviewResource($targets->preview($request->filter(), $request->skuIds(), $request->discountPercent(), PreviewPromotionRequest::SAMPLE));
    }
}
