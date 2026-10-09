<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Data\PricedCartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cart (08-api-contract.md §3, BR-CU06): lines with prices resolved now (BR-PR04), `removed_items` for
 * lines dropped because their product became inactive (BR-P04), `items_total` and the MOV check (BR-MOV02).
 * Money is a 2-decimal string; `available` is in base units (D37).
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
            'items' => array_map(fn (PricedCartLine $line): array => [
                'sku_id' => $line->skuId,
                'product_name' => $line->productName,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'unit_price' => $line->price->unitPrice,
                'tier_min_quantity' => $line->price->tierMinQuantity,
                'line_total' => $line->price->lineTotal,
                'moq' => $line->moq,
                'available' => $line->available,
            ], $cart->lines),
            'removed_items' => $cart->removed,
            'items_total' => $cart->itemsTotal,
            'mov' => [
                'enabled' => $cart->movEnabled,
                'amount' => $cart->movAmount,
                'satisfied' => $cart->movSatisfied,
            ],
        ];
    }
}
