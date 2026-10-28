<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;

/**
 * One line to order, wherever it came from: the customer's cart (Checkout) or the merchant's form
 * (PlaceOrderForCustomer, BR-O11). Quantity in the line's SKU unit (V2 Task 13, D91). Expects the unit's `unit`, `tiers`,
 * `sku.product` and `sku.inventory` loaded (Catalog's CartSkuQuery::RELATIONS); `sku` is the unit's SKU.
 */
final readonly class CartLineInput
{
    public Sku $sku;

    public function __construct(
        public SkuUnit $unit,
        public int $quantity,
    ) {
        $this->sku = $unit->sku;
    }

    /** BR-U03: the base units the line takes of the SKU's stock. */
    public function quantityBase(): int
    {
        return $this->quantity * $this->unit->conversion_factor;
    }
}
