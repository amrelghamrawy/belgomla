<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\SkuInventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One SKU's stock in base units (BR-INV02, 08-api-contract.md §7). `min_allowed` is Reserved: the lowest
 * On Hand an adjustment may set (BR-INV08). `low_stock` is D36 (Available ≤ MOQ × conversion factor).
 * A SKU without a stock row has none. Expects `product` and `inventory` loaded.
 *
 * @property-read Sku $resource
 */
final class InventoryRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sku = $this->resource;
        $product = $sku->relationLoaded('product') ? $sku->product : null;
        $stock = $sku->relationLoaded('inventory') ? $sku->inventory : null;

        $onHand = $stock instanceof SkuInventory ? $stock->on_hand : 0;
        $reserved = $stock instanceof SkuInventory ? $stock->reserved : 0;
        $available = $onHand - $reserved;

        return [
            'sku_id' => $sku->id,
            'product_id' => $sku->product_id,
            'code' => $sku->code,
            'name' => $product instanceof Product ? $product->name : null,
            'status' => $product instanceof Product ? $product->status->value : null,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
            'min_allowed' => $reserved,
            'moq' => $sku->moq,
            'conversion_factor' => $sku->conversion_factor,
            'low_stock' => $available <= $sku->moq * $sku->conversion_factor,
        ];
    }
}
