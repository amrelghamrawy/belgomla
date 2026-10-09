<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Exceptions\CartLineInvalid;

/**
 * Whether a quantity of a SKU can go into the cart now (08-api-contract.md §3), the cart's share of BR-PR06:
 * the product is active (BR-P04, D21), the quantity reaches the SKU's MOQ (BR-MOQ01) and the stock covers
 * it in base units (BR-INV01, BR-U03). Checkout repeats these under lock. Expects `product` and `inventory` loaded.
 */
final readonly class OrderableQuantity
{
    public function assert(Sku $sku, int $quantity): void
    {
        if ($sku->product->status !== ProductStatus::Active) {
            throw CartLineInvalid::productInactive($sku->id);
        }

        if ($quantity < $sku->moq) {
            throw CartLineInvalid::belowMoq($sku->id, $sku->moq);
        }

        $available = $sku->inventory->available ?? 0;

        if ($quantity * $sku->conversion_factor > $available) {
            throw CartLineInvalid::insufficientStock($sku->id, intdiv(max(0, $available), $sku->conversion_factor));
        }
    }
}
