<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Pricing\Actions\CancelPromotion;
use App\Modules\Pricing\Http\Resources\PromotionResource;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Queries\PromotionsQuery;

final readonly class PromotionCancelController
{
    /** POST /api/dashboard/promotions/{id}/cancel — BR-PR15, D90 f */
    public function store(Promotion $promotion, CancelPromotion $cancel, PromotionsQuery $promotions): PromotionResource
    {
        return new PromotionResource($promotions->detail($cancel->handle($promotion)));
    }
}
