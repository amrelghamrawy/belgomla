<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;

/**
 * One line to order, wherever it came from: the customer's cart (Checkout) or the merchant's form
 * (PlaceOrderForCustomer, BR-O11). Quantity in the line's SKU unit (V2 Task 13, D91). Expects the unit's `unit`, `tiers`,
 * `sku.product` and `sku.inventory` loaded (Catalog's CartSkuQuery::RELATIONS); `sku` is the unit's SKU.
 *
 * A bundle line (BR-P15, V2 Task 15, D93) takes no stock of its own SKU: it takes `quantity × item quantity × factor`
 * base units of each component's SKU (`parts()`), whose rows checkout locks and checks (`stockSkus()`).
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

    public function isBundle(): bool
    {
        return $this->sku->product->isBundle();
    }

    /**
     * The stock the line takes, per SKU: its own, or each component's for a bundle (D93).
     *
     * @return list<array{0: Sku, 1: int}> SKU and base units, in component order
     */
    public function parts(): array
    {
        if (! $this->isBundle()) {
            return [[$this->sku, $this->quantityBase()]];
        }

        return array_values($this->sku->bundleItems->map(fn (BundleItem $item): array => [$item->componentUnit->sku, $this->quantity * $item->baseQuantity()])->all());
    }

    /**
     * The SKUs whose stock rows the line needs.
     *
     * @return list<Sku>
     */
    public function stockSkus(): array
    {
        return array_map(fn (array $part): Sku => $part[0], $this->parts());
    }

    /**
     * The SKUs the line's prices depend on (its own, and for a bundle its components, whose prices split the bundle's,
     * D93): what the PricingContext must know the promotions of (BR-PR15).
     *
     * @return list<int>
     */
    public function pricedSkuIds(): array
    {
        return [$this->sku->id, ...($this->isBundle() ? array_map(fn (Sku $sku): int => $sku->id, $this->stockSkus()) : [])];
    }
}
