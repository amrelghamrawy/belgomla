<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Data\PricedCartLine;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Pricing\Services\PriceResolver;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Prices a cart for display, BR-PR06 steps 3–7 without the writes: each line through PriceResolver (BR-PR04),
 * the items total, and the minimum order value against it (BR-MOV02). Pre-order lines (BR-O14) are priced the same way
 * into their own indicative total, without the minimum order value (D88). Expects the lines' `sku.product`,
 * `sku.sellingUnit`, `sku.tiers` and `sku.inventory` loaded (CartQuery) and lines that can no longer be ordered removed.
 */
final readonly class CartPricer
{
    public function __construct(
        private PriceResolver $prices,
        private CurrentTenantSettingsQuery $settings,
    ) {}

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  list<array{product_name: string, reason: string}>  $removed
     * @param  Collection<int, CartItem>|null  $preorders
     */
    public function price(Collection $items, array $removed = [], ?Collection $preorders = null): PricedCart
    {
        [$lines, $total] = $this->lines($items, preorder: false);
        [$preorderLines, $preorderTotal] = $this->lines($preorders ?? new Collection, preorder: true);
        $mov = $this->settings->minimumOrderValue();

        return new PricedCart(
            lines: $lines,
            removed: $removed,
            itemsTotal: $total,
            movEnabled: $mov['enabled'],
            movAmount: $mov['amount'],
            movSatisfied: $mov['amount'] === null || Money::compare($total, $mov['amount']) >= 0,
            preorderLines: $preorderLines,
            preorderTotal: $preorderTotal,
        );
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @return array{0: list<PricedCartLine>, 1: string}
     */
    private function lines(Collection $items, bool $preorder): array
    {
        $lines = [];
        $total = '0.00';

        foreach ($items as $item) {
            $sku = $item->sku;
            $price = $this->prices->resolve($sku, $item->quantity);
            $total = Money::add($total, $price->lineTotal);

            $lines[] = new PricedCartLine(
                skuId: $sku->id,
                productName: $sku->product->name,
                productSlug: $sku->product->slug,
                unit: $sku->sellingUnit->name,
                conversionFactor: $sku->conversion_factor,
                quantity: $item->quantity,
                price: $price,
                moq: $sku->moq,
                available: $preorder ? 0 : $sku->inventory->available ?? 0,
                expectedAt: $preorder ? $sku->product->expected_at?->toDateString() : null,
            );
        }

        return [$lines, $total];
    }
}
