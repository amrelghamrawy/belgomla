<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/dashboard/promotions/preview (BR-PR15, D90 d): `{ skus_count, sample: [{ sku_id, code, product_name,
 * base_price, promotion_price }] }`; `promotion_price` is null when no % was sent.
 *
 * @property-read array{skus_count: int, sample: list<array{sku_id: int, code: string, product_name: string, base_price: string, promotion_price: string|null}>} $resource
 */
final class PromotionPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'skus_count' => $this->resource['skus_count'],
            'sample' => $this->resource['sample'],
        ];
    }
}
