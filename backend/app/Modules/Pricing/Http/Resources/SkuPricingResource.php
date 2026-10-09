<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The price set of one SKU for the dashboard pricing form (08-api-contract.md §6). Tiers keep the % they
 * were entered as (null for absolute prices); `discount_percent` is the one shown next to the base price.
 *
 * @property-read Sku $resource
 */
final class SkuPricingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sku = $this->resource;
        $resolver = app(PriceResolver::class);

        return [
            'product_id' => $sku->product_id,
            'sku_id' => $sku->id,
            'code' => $sku->code,
            'cover_price' => $sku->cover_price,
            'base_price' => $sku->base_price,
            'base_price_percent' => $sku->base_price_percent,
            'discount_percent' => $resolver->baseDiscountPercent($sku),
            'moq' => $sku->moq,
            'tiers' => array_map(fn (TierData $tier): array => [
                'min_quantity' => $tier->minQuantity,
                'unit_price' => $tier->unitPrice,
                'discount_percent' => $tier->discountPercent,
            ], $resolver->tiers($sku)),
        ];
    }
}
