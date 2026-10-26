<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A promotion on the dashboard (BR-PR15, V2 Task 12): its % off the base price as a 2-decimal string, the window as
 * ISO-8601 UTC, the status as of now (D90 c: a scheduled promotion that started reads `active` before the tick moves
 * it), its target (`filter` with the stored product filter, or `skus`) and how many SKUs it covers. The detail of a
 * promotion on an explicit list adds `skus` (`{ sku_id, code, product_name }`) for the edit form.
 *
 * @property-read Promotion $resource
 */
final class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $promotion = $this->resource;

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'discount_percent' => $promotion->discount_percent,
            'starts_at' => $promotion->starts_at->toIso8601String(),
            'ends_at' => $promotion->ends_at->toIso8601String(),
            'status' => $promotion->currentStatus()->value,
            'target' => $promotion->filter === null ? 'skus' : 'filter',
            'filter' => $promotion->filter,
            'skus_count' => $promotion->skus_count ?? 0,
            'skus' => $this->when($promotion->relationLoaded('skus'), fn (): array => $promotion->skus->map(fn (Sku $sku): array => [
                'sku_id' => $sku->id,
                'code' => $sku->code,
                'product_name' => $sku->product->name,
            ])->values()->all()),
            'cancelled_at' => $promotion->cancelled_at?->toIso8601String(),
            'created_at' => $promotion->created_at->toIso8601String(),
        ];
    }
}
