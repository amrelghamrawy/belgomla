<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\BundlePricing;
use App\Support\Money;

/**
 * BR-P15 (V2 Task 15, D93): the price of a bundle. `fixed` is the merchant's price; `percent_off_sum` is the sum of the
 * components' base prices (each component unit's own base price × its quantity in the bundle, BR-PR01) less the %, rounded
 * half-up to the piaster (D27) and never below 0.01. The result is the bundle unit's base price (SaveBundlePricing and
 * RepriceBundles store it), so groups, promotions and negotiated prices then apply to the bundle price like to any SKU
 * unit (BR-PR14, BR-PR15) through the PriceResolver.
 *
 * Expects the items' `componentUnit` loaded (with its `tiers` for the shopper's sum, `listedSum`).
 */
final readonly class BundlePricer
{
    public function __construct(
        private PercentPricing $percent,
        private PriceResolver $prices,
    ) {}

    /**
     * @param  iterable<BundleItem>  $items
     */
    public function price(BundlePricingData $pricing, iterable $items): string
    {
        if ($pricing->mode === BundlePriceMode::Fixed) {
            return Money::normalize($pricing->value);
        }

        $sum = $this->sum($items);

        if (Money::compare($pricing->value, '0.00') === 0) {
            return $sum; // a grade set at the plain sum (D93 g)
        }

        $price = $this->percent->priceFromPercent($sum, $pricing->value);

        return Money::compare($price, '0.01') < 0 ? '0.01' : $price;
    }

    /**
     * Σ component base price × quantity: what the components cost at their base prices (the «وفّر» reference on the
     * dashboard, and the base of `percent_off_sum`).
     *
     * @param  iterable<BundleItem>  $items
     */
    public function sum(iterable $items): string
    {
        $sum = '0.00';

        foreach ($items as $item) {
            $sum = Money::add($sum, Money::times($item->componentUnit->base_price, $item->quantity));
        }

        return $sum;
    }

    /**
     * Σ what this shopper would pay for each component bought on its own (its listed price, BR-PR14, BR-PR15) × quantity:
     * the storefront's «وفّر» reference.
     *
     * @param  iterable<BundleItem>  $items
     */
    public function listedSum(iterable $items, ?PricingContext $context): string
    {
        $sum = '0.00';

        foreach ($items as $item) {
            $sum = Money::add($sum, Money::times($this->prices->listed($item->componentUnit, $context)->basePrice, $item->quantity));
        }

        return $sum;
    }

    /** The stored pricing of a bundle SKU; null for a simple product's SKU. */
    public function pricingOf(Sku $sku): ?BundlePricingData
    {
        $row = BundlePricing::query()->find($sku->id);

        return $row instanceof BundlePricing ? new BundlePricingData($row->mode, $row->value) : null;
    }
}
