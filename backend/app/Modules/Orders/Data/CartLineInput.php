<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Catalog\Models\Sku;

/**
 * One line to order, wherever it came from: the customer's cart (Checkout) or the merchant's form
 * (PlaceOrderForCustomer, BR-O11). Quantity in selling units. Expects the SKU's `product`, `sellingUnit` and `tiers`
 * loaded (Catalog's CartSkuQuery::RELATIONS).
 */
final readonly class CartLineInput
{
    public function __construct(
        public Sku $sku,
        public int $quantity,
    ) {}
}
