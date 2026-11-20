<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The price set of one SKU unit for the dashboard pricing form (08-api-contract.md §6, V2 Task 13, D91). Tiers keep the %
 * they were entered as (null for absolute prices); `discount_percent` is the one shown next to the base price.
 *
 * @property-read SkuUnit $resource
 */
final class SkuPricingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->resource;
        $resolver = app(PriceResolver::class);

        return [
            'product_id' => $unit->sku->product_id,
            'sku_id' => $unit->sku_id,
            'sku_unit_id' => $unit->id,
            'code' => $unit->sku->code,
            'unit' => $unit->unit->name,
            'conversion_factor' => $unit->conversion_factor,
            'cover_price' => $unit->cover_price,
            'base_price' => $unit->base_price,
            'base_price_percent' => $unit->base_price_percent,
            'discount_percent' => $resolver->baseDiscountPercent($unit),
            'moq' => $unit->moq,
            'tiers' => array_map(fn (TierData $tier): array => [
                'min_quantity' => $tier->minQuantity,
                'unit_price' => $tier->unitPrice,
                'discount_percent' => $tier->discountPercent,
            ], $resolver->tiers($unit)),
        ];
    }
}
