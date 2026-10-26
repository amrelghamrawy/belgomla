<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\ListedPrice;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\ResolvedPrice;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * The single price authority (07-domain-model.md §4), used by the storefront, the cart, checkout (BR-PR06), the
 * merchant's quote and order, order edits and pre-order conversion. Pure: it reads the SKU, its already-loaded `tiers`
 * and the customer's PricingContext (read once per request by Customers' PricingContextQuery), never the database.
 *
 * BR-PR04: the tier with the highest `min_quantity ≤ quantity` wins, else the base price. MOQ plays no part here (BR-MOQ01
 * is a separate checkout assertion). Tiers are per SKU only (BR-PR02).
 *
 * BR-PR14 (D89), with a context: (1) a price negotiated with the customer for the SKU (BR-PR13) wins outright, at every
 * quantity, even above the normal price; (2) otherwise the customer pays the lower of the normal price (base or tier) and
 * the group price, `base × (100 − group %) / 100` rounded half-up to the piaster (BR-PR12, D27). The result says which
 * (`source`); `discountPercent` stays relative to the cover price (BR-PR10).
 */
final readonly class PriceResolver
{
    public function __construct(private PercentPricing $percent) {}

    public function resolve(Sku $sku, int $quantity, ?PricingContext $context = null): ResolvedPrice
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("A quantity is a whole number of at least 1 (BR-U05), {$quantity} given.");
        }

        $negotiated = $context?->customerPrice($sku->id);

        if ($negotiated !== null) {
            return $this->resolved($sku, $negotiated, null, $this->impliedDiscount($sku, $negotiated), $quantity, PriceSource::Customer);
        }

        $tier = null;

        foreach ($this->loadedTiers($sku) as $candidate) {
            if ($candidate->min_quantity <= $quantity && (! $tier instanceof SkuPriceTier || $candidate->min_quantity > $tier->min_quantity)) {
                $tier = $candidate;
            }
        }

        $group = $this->groupPrice($sku, $context);
        $unitPrice = $tier instanceof SkuPriceTier ? $tier->unit_price : $sku->base_price;

        if ($group !== null && Money::compare($group, $unitPrice) < 0) {
            return $this->resolved($sku, $group, null, $this->impliedDiscount($sku, $group), $quantity, PriceSource::Group);
        }

        return $tier instanceof SkuPriceTier
            ? $this->resolved($sku, $tier->unit_price, $tier->min_quantity, $tier->discount_percent ?? $this->impliedDiscount($sku, $tier->unit_price), $quantity, PriceSource::Tier)
            : $this->resolved($sku, $sku->base_price, null, $this->baseDiscountPercent($sku), $quantity, PriceSource::Base);
    }

    /**
     * BR-PR14: what the storefront and the price list show this customer for the SKU (see ListedPrice). Without a
     * context, or a context that changes nothing for the SKU, it is the base price and the tiers as stored.
     */
    public function listed(Sku $sku, ?PricingContext $context = null): ListedPrice
    {
        $negotiated = $context?->customerPrice($sku->id);

        if ($negotiated !== null) {
            return new ListedPrice($negotiated, PriceSource::Customer, $this->impliedDiscount($sku, $negotiated), []);
        }

        $tiers = $this->tiers($sku);
        $group = $this->groupPrice($sku, $context);

        if ($group !== null && Money::compare($group, $sku->base_price) < 0) {
            $below = array_values(array_filter($tiers, fn (TierData $tier): bool => Money::compare($tier->unitPrice ?? $sku->base_price, $group) < 0));

            return new ListedPrice($group, PriceSource::Group, $this->impliedDiscount($sku, $group), $below);
        }

        return new ListedPrice($sku->base_price, PriceSource::Base, $this->baseDiscountPercent($sku), $tiers);
    }

    /**
     * The tiers as stored, lowest threshold first.
     *
     * @return list<TierData>
     */
    public function tiers(Sku $sku): array
    {
        $tiers = [];

        foreach ($this->loadedTiers($sku)->sortBy('min_quantity') as $tier) {
            $tiers[] = new TierData($tier->min_quantity, $tier->unit_price, $tier->discount_percent);
        }

        return $tiers;
    }

    /**
     * The discount shown next to the base price (BR-PR10): the % it was entered as, else the % it
     * implies off the cover price, else null when the SKU has no cover price.
     */
    public function baseDiscountPercent(Sku $sku): ?string
    {
        return $sku->base_price_percent ?? $this->impliedDiscount($sku, $sku->base_price);
    }

    private function resolved(Sku $sku, string $unitPrice, ?int $tierMinQuantity, ?string $discount, int $quantity, PriceSource $source): ResolvedPrice
    {
        return new ResolvedPrice(
            unitPrice: $unitPrice,
            tierMinQuantity: $tierMinQuantity,
            discountPercent: $discount,
            coverPrice: $sku->cover_price,
            lineTotal: Money::times($unitPrice, $quantity),
            source: $source,
        );
    }

    /** BR-PR12, D27: the base price less the group's %, half-up to the piaster and never below 0.01; null without one. */
    private function groupPrice(Sku $sku, ?PricingContext $context): ?string
    {
        $percent = $context?->groupDiscount();

        if ($percent === null) {
            return null;
        }

        $price = $this->percent->priceFromPercent($sku->base_price, $percent);

        return Money::compare($price, '0.01') < 0 ? '0.01' : $price;
    }

    private function impliedDiscount(Sku $sku, string $price): ?string
    {
        return $sku->cover_price === null ? null : $this->percent->percentOff($sku->cover_price, $price);
    }

    /**
     * @return Collection<int, SkuPriceTier>
     */
    private function loadedTiers(Sku $sku): Collection
    {
        if (! $sku->relationLoaded('tiers')) {
            throw new LogicException('Load the SKU tiers before resolving its price; the resolver never queries.');
        }

        return $sku->tiers;
    }
}
