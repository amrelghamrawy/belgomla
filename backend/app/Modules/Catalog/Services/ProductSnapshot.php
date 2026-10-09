<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;

/**
 * The flat view of a product + its SKU that audit records store (BR-L). Flat `sku.*` keys let the
 * AuditLogger keep only the changed fields, so a price or MOQ change shows up as exactly that.
 */
final readonly class ProductSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function of(Product $product): array
    {
        $sku = $product->sku;

        return [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'description' => $product->description,
            'status' => $product->status->value,
            'custom_fields' => $product->custom_fields,
            ...($sku instanceof Sku ? $this->sku($sku) : []),
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
            'sku.cover_price' => $sku->cover_price,
            'sku.base_price' => $sku->base_price,
            'sku.base_price_percent' => $sku->base_price_percent,
            'sku.moq' => $sku->moq,
        ];
    }
}
