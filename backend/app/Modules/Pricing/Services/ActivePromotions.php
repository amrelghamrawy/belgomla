<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Data\PromotionPrice;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;

/**
 * BR-PR15 (V2 Task 12, D90): which promotion is live on each SKU at a moment, read from the materialised
 * `promotion_skus` in one query for the PricingContext, so the PriceResolver stays free of the database. Live = status
 * scheduled or active and `starts_at ≤ at < ends_at` (the times decide, not the tick). Promotions never stack: a SKU in
 * several takes the highest %, the oldest promotion on a tie.
 */
final readonly class ActivePromotions
{
    /**
     * @param  list<int>  $skuIds
     * @return array<int, PromotionPrice> sku_id => its live promotion
     */
    public function forSkus(array $skuIds, ?CarbonImmutable $at = null): array
    {
        if ($skuIds === []) {
            return [];
        }

        $rows = $this->live($at ?? Date::now()->toImmutable())
            ->whereIn('promotion_skus.sku_id', array_values(array_unique($skuIds)))
            ->orderBy('promotion_skus.sku_id')
            ->orderByDesc('promotions.discount_percent')
            ->orderBy('promotions.id')
            ->get(['promotion_skus.sku_id', 'promotions.id', 'promotions.name', 'promotions.discount_percent', 'promotions.ends_at']);

        $promotions = [];

        foreach ($rows as $row) {
            $skuId = filter_var($row->getAttribute('sku_id'), FILTER_VALIDATE_INT);

            if (is_int($skuId)) {
                $promotions[$skuId] ??= new PromotionPrice($row->id, $row->name, $row->discount_percent, $row->ends_at);
            }
        }

        return $promotions;
    }

    /**
     * The ids of the SKUs on a live promotion now, as a subquery (the storefront's «عروض» list, `on_offer`).
     */
    public function skuIdsQuery(?CarbonImmutable $at = null): QueryBuilder
    {
        return $this->live($at ?? Date::now()->toImmutable())->select('promotion_skus.sku_id')->toBase();
    }

    /**
     * @return Builder<Promotion>
     */
    private function live(CarbonImmutable $at): Builder
    {
        return Promotion::query()
            ->join('promotion_skus', 'promotion_skus.promotion_id', '=', 'promotions.id')
            ->whereIn('promotions.status', [PromotionStatus::Scheduled, PromotionStatus::Active])
            ->where('promotions.starts_at', '<=', $at)
            ->where('promotions.ends_at', '>', $at);
    }
}
