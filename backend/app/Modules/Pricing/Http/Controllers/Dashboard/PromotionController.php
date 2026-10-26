<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Pricing\Actions\CreatePromotion;
use App\Modules\Pricing\Actions\UpdatePromotion;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Http\Requests\ListPromotionsRequest;
use App\Modules\Pricing\Http\Requests\StorePromotionRequest;
use App\Modules\Pricing\Http\Requests\UpdatePromotionRequest;
use App\Modules\Pricing\Http\Resources\PromotionResource;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Queries\PromotionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/dashboard/promotions — BR-PR15 (V2 Task 12, D90). {promotion} is tenant-scoped (BR-T04). */
final readonly class PromotionController
{
    public function index(ListPromotionsRequest $request, PromotionsQuery $promotions): AnonymousResourceCollection
    {
        return PromotionResource::collection($promotions->paginate($request->status(), $request->perPage()));
    }

    public function store(StorePromotionRequest $request, CreatePromotion $create, PromotionsQuery $promotions): JsonResponse
    {
        return new PromotionResource($promotions->detail($create->handle(PromotionData::fromRequest($request), $request->actorId())))->response()->setStatusCode(201);
    }

    public function show(Promotion $promotion, PromotionsQuery $promotions): PromotionResource
    {
        return new PromotionResource($promotions->detail($promotion));
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion, UpdatePromotion $update, PromotionsQuery $promotions): PromotionResource
    {
        return new PromotionResource($promotions->detail($update->handle($promotion, PromotionData::fromRequest($request))));
    }
}
