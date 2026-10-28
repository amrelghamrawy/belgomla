<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;

/**
 * The flat view of a product + its SKU that audit records store (BR-L). Flat `sku.*` keys let the
 * AuditLogger keep only the changed fields; `sku.units` lists the active units (D91). `withPricing: false` leaves out
 * each unit's prices, MOQ and tiers for `product.updated`, because UpdateSkuPricing audits those itself.
 */
final readonly class ProductSnapshot
{
    public function __construct(private PriceResolver $prices) {}

    /**
     * @return array<string, mixed>
     */
    public function of(Product $product, bool $withPricing = true): array
    {
        $sku = $product->sku;

        return [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'description' => $product->description,
            'status' => $product->status->value,
            'custom_fields' => $product->custom_fields,
            'preorder_enabled' => $product->preorder_enabled,
            'expected_at' => $product->expected_at?->toDateString(),
            'preorder_limit' => $product->preorder_limit,
            ...($sku instanceof Sku ? $this->sku($sku, $withPricing) : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sku(Sku $sku, bool $withPricing): array
    {
        return [
            'sku.code' => $sku->code,
            'sku.base_unit_id' => $sku->base_unit_id,
            'sku.units' => $sku->loadMissing('activeUnits')->activeUnits->map(fn (SkuUnit $unit): array => [
                'unit_id' => $unit->unit_id,
                'conversion_factor' => $unit->conversion_factor,
                'is_default' => $unit->is_default,
                ...($withPricing ? $this->pricing($unit) : []),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(SkuUnit $unit): array
    {
        return [
            'cover_price' => $unit->cover_price,
            'base_price' => $unit->base_price,
            'base_price_percent' => $unit->base_price_percent,
            'moq' => $unit->moq,
            'tiers' => array_map(
                fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'unit_price' => $tier->unitPrice, 'discount_percent' => $tier->discountPercent],
                $this->prices->tiers($unit->loadMissing('tiers')),
            ),
        ];
    }
}
