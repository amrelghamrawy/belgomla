<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;

/**
 * The flat view of a product + its SKU that audit records store (BR-L). Flat `sku.*` keys let the
 * AuditLogger keep only the changed fields. `withPricing: false` leaves out prices, MOQ and tiers for
 * `product.updated`, because UpdateSkuPricing audits those itself.
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
            ...($sku instanceof Sku ? $this->sku($sku) : []),
            ...($sku instanceof Sku && $withPricing ? $this->pricing($sku) : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sku(Sku $sku): array
    {
        return [
            'sku.code' => $sku->code,
            'sku.selling_unit_id' => $sku->selling_unit_id,
            'sku.base_unit_id' => $sku->base_unit_id,
            'sku.conversion_factor' => $sku->conversion_factor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(Sku $sku): array
    {
        return [
            'sku.cover_price' => $sku->cover_price,
            'sku.base_price' => $sku->base_price,
            'sku.base_price_percent' => $sku->base_price_percent,
            'sku.moq' => $sku->moq,
            'sku.tiers' => array_map(
                fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'unit_price' => $tier->unitPrice, 'discount_percent' => $tier->discountPercent],
                $this->prices->tiers($sku->loadMissing('tiers')),
            ),
        ];
    }
}
