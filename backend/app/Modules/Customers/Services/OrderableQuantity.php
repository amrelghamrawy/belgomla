<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Services\BundleAvailability;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Exceptions\CartLineInvalid;

/**
 * Whether a quantity of a SKU unit can go into the cart now (08-api-contract.md §3), the cart's share of BR-PR06:
 * the product is active (BR-P04, D21) and the unit still sold (D91), the quantity reaches the unit's MOQ (BR-MOQ01) and
 * the stock covers it in base units (BR-INV01, BR-U03) next to what the cart already holds of the SKU in its other units
 * (`$heldBase`: piece and carton draw on the same stock). A pre-order line (BR-O14, D88) needs a Coming Soon product with
 * pre-orders on and the MOQ, never stock. Checkout and the pre-order confirmation repeat these under lock. Expects the
 * unit's `sku.product` and `sku.inventory` loaded.
 *
 * A bundle (BR-P15, V2 Task 15, D93) has no stock of its own: it can go in while its components allow that many bundles
 * (BundleAvailability; a component that can no longer be ordered makes it `product_inactive`). What the cart holds of a
 * component loose is checked with the bundle at checkout, not here.
 */
final readonly class OrderableQuantity
{
    public function __construct(private BundleAvailability $bundles) {}

    public function assert(SkuUnit $unit, int $quantity, CartLineKind $kind = CartLineKind::Order, int $heldBase = 0): void
    {
        if ($kind === CartLineKind::Preorder) {
            $this->assertPreorderable($unit, $quantity);

            return;
        }

        if ($unit->sku->product->status !== ProductStatus::Active || ! $unit->sku->isActive() || ! $unit->isActive()) {
            throw CartLineInvalid::productInactive($unit->sku_id, $unit->id);
        }

        if ($unit->sku->product->isBundle()) {
            $this->assertBundle($unit, $quantity);

            return;
        }

        $this->assertMoq($unit, $quantity);

        $available = max(0, ($unit->sku->inventory->available ?? 0) - $heldBase);

        if ($quantity * $unit->conversion_factor > $available) {
            throw CartLineInvalid::insufficientStock($unit->sku_id, $unit->id, intdiv($available, $unit->conversion_factor));
        }
    }

    /** BR-O14: whether the unit's product takes pre-orders now (and the unit is still sold). */
    public function preorderable(SkuUnit $unit): bool
    {
        $product = $unit->sku->product;

        return $product->status === ProductStatus::ComingSoon && $product->preorder_enabled && $unit->sku->isActive() && $unit->isActive();
    }

    private function assertPreorderable(SkuUnit $unit, int $quantity): void
    {
        if (! $this->preorderable($unit)) {
            throw CartLineInvalid::preorderUnavailable($unit->sku_id, $unit->id);
        }

        $this->assertMoq($unit, $quantity);
    }

    /** D93: every component can be ordered, the MOQ, and the components allow that many bundles. */
    private function assertBundle(SkuUnit $unit, int $quantity): void
    {
        $items = $unit->sku->bundleItems;

        if ($items->isEmpty() || ! $items->every($this->bundles->orderable(...))) {
            throw CartLineInvalid::productInactive($unit->sku_id, $unit->id);
        }

        $this->assertMoq($unit, $quantity);

        $available = $this->bundles->available($unit->sku);

        if ($quantity > $available) {
            throw CartLineInvalid::insufficientStock($unit->sku_id, $unit->id, $available);
        }
    }

    private function assertMoq(SkuUnit $unit, int $quantity): void
    {
        if ($quantity < $unit->moq) {
            throw CartLineInvalid::belowMoq($unit->sku_id, $unit->id, $unit->moq);
        }
    }
}
