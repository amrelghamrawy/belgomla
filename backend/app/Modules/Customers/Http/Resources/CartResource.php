<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Data\PricedCartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cart (08-api-contract.md §3, BR-CU06): lines with prices resolved now (BR-PR04), `removed_items` for
 * lines dropped because their product became inactive (BR-P04) or stopped taking pre-orders, `items_total` and the MOV
 * check (BR-MOV02). Each line says where its price came from (`price_source`: base, tier, group or customer, BR-PR14). `preorders` holds the pre-order lines (BR-O14) in the same shape plus `expected_at`, with their
 * indicative `items_total` (no MOV, D88). Money is a 2-decimal string; `available` is in base units (D37).
 *
 * @property-read PricedCart $resource
 */
final class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cart = $this->resource;

        return [
            'items' => array_map($this->line(...), $cart->lines),
            'removed_items' => $cart->removed,
            'items_total' => $cart->itemsTotal,
            'mov' => [
                'enabled' => $cart->movEnabled,
                'amount' => $cart->movAmount,
                'satisfied' => $cart->movSatisfied,
            ],
            'preorders' => [
                'items' => array_map(fn (PricedCartLine $line): array => [...$this->line($line), 'expected_at' => $line->expectedAt], $cart->preorderLines),
                'items_total' => $cart->preorderTotal,
            ],
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function line(PricedCartLine $line): array
    {
        return [
            'sku_id' => $line->skuId,
            'product_name' => $line->productName,
            'product_slug' => $line->productSlug,
            'unit' => $line->unit,
            'conversion_factor' => $line->conversionFactor,
            'quantity' => $line->quantity,
            'unit_price' => $line->price->unitPrice,
            'tier_min_quantity' => $line->price->tierMinQuantity,
            'price_source' => $line->price->source->value,
            'line_total' => $line->price->lineTotal,
            'moq' => $line->moq,
            'available' => $line->available,
        ];
    }
}
