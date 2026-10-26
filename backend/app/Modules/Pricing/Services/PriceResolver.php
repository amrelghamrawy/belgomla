<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\ListedPrice;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\PromotionPrice;
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
 * quantity, even above the normal price; (2) otherwise the customer pays the lowest of the normal price (base or tier),
 * the group price, `base × (100 − group %) / 100` (BR-PR12), and the price of the SKU's live promotion,
 * `base × (100 − promotion %) / 100` (BR-PR15, D90), each rounded half-up to the piaster (D27) and never below 0.01; on a
 * tie the earlier of the three is the source. The result says which (`source`, and the `promotion` when it is one);
 * `discountPercent` stays relative to the cover price (BR-PR10).
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

        $unitPrice = $tier instanceof SkuPriceTier ? $tier->unit_price : $sku->base_price;
        $special = $this->specialPrice($sku, $context, $unitPrice);

        if ($special !== null) {
            [$price, $source, $promotion] = $special;

            return $this->resolved($sku, $price, null, $this->impliedDiscount($sku, $price), $quantity, $source, $promotion);
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
        $special = $this->specialPrice($sku, $context, $sku->base_price);

        if ($special !== null) {
            [$price, $source, $promotion] = $special;
            $below = array_values(array_filter($tiers, fn (TierData $tier): bool => Money::compare($tier->unitPrice ?? $sku->base_price, $price) < 0));

            return new ListedPrice($price, $source, $this->impliedDiscount($sku, $price), $below, $promotion);
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

    /** BR-PR15: the price of the SKU under a promotion of `$percent` off its base price (the promotion preview). */
    public function promotionPrice(Sku $sku, string $percent): string
    {
        return $this->percentOffBase($sku, $percent) ?? $sku->base_price;
    }

    private function resolved(Sku $sku, string $unitPrice, ?int $tierMinQuantity, ?string $discount, int $quantity, PriceSource $source, ?PromotionPrice $promotion = null): ResolvedPrice
    {
        return new ResolvedPrice(
            unitPrice: $unitPrice,
            tierMinQuantity: $tierMinQuantity,
            discountPercent: $discount,
            coverPrice: $sku->cover_price,
            lineTotal: Money::times($unitPrice, $quantity),
            source: $source,
            promotion: $promotion,
        );
    }

    /**
     * BR-PR14, BR-PR15 (D89, D90): the group or promotion price when one is lower than `$normal` (the group first on a tie),
     * with its source and promotion; null when neither lowers it.
     *
     * @return array{0: string, 1: PriceSource, 2: PromotionPrice|null}|null
     */
    private function specialPrice(Sku $sku, ?PricingContext $context, string $normal): ?array
    {
        $best = null;
        $group = $this->percentOffBase($sku, $context?->groupDiscount());

        if ($group !== null && Money::compare($group, $normal) < 0) {
            $best = [$group, PriceSource::Group, null];
        }

        $promotion = $context?->promotion($sku->id);
        $promoted = $this->percentOffBase($sku, $promotion?->discountPercent);

        if ($promoted !== null && Money::compare($promoted, $best[0] ?? $normal) < 0) {
            return [$promoted, PriceSource::Promotion, $promotion];
        }

        return $best;
    }

    /** BR-PR12, BR-PR15, D27: the base price less a group's or a promotion's %, half-up to the piaster and never below 0.01; null without one. */
    private function percentOffBase(Sku $sku, ?string $percent): ?string
    {
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
