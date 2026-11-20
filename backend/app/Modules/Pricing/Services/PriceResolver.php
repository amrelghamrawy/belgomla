<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\SkuUnit;
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
 * merchant's quote and order, order edits and pre-order conversion. Pure: it reads the SKU unit (V2 Task 13, D91: every
 * unit of a SKU has its own base price, cover price and tiers), its already-loaded `tiers` and the customer's PricingContext (read once per request by Customers' PricingContextQuery), never the database.
 *
 * BR-PR04: the tier with the highest `min_quantity ≤ quantity` wins, else the base price. MOQ plays no part here (BR-MOQ01
 * is a separate checkout assertion). Tiers are per SKU unit only (BR-PR02, BR-U04).
 *
 * BR-PR14 (D89), with a context: (1) a price negotiated with the customer for the SKU unit (BR-PR13, D91) wins outright, at every
 * quantity, even above the normal price; (2) otherwise the customer pays the lowest of the normal price (base or tier),
 * the group price, `base × (100 − group %) / 100` (BR-PR12), and the price of the SKU's live promotion on this unit's base price,
 * `base × (100 − promotion %) / 100` (BR-PR15, D90), each rounded half-up to the piaster (D27) and never below 0.01; on a
 * tie the earlier of the three is the source. The result says which (`source`, and the `promotion` when it is one);
 * `discountPercent` stays relative to the cover price (BR-PR10).
 */
final readonly class PriceResolver
{
    public function __construct(private PercentPricing $percent) {}

    public function resolve(SkuUnit $unit, int $quantity, ?PricingContext $context = null): ResolvedPrice
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("A quantity is a whole number of at least 1 (BR-U05), {$quantity} given.");
        }

        $negotiated = $context?->customerPrice($unit->id);

        if ($negotiated !== null) {
            return $this->resolved($unit, $negotiated, null, $this->impliedDiscount($unit, $negotiated), $quantity, PriceSource::Customer);
        }

        $tier = null;

        foreach ($this->loadedTiers($unit) as $candidate) {
            if ($candidate->min_quantity <= $quantity && (! $tier instanceof SkuPriceTier || $candidate->min_quantity > $tier->min_quantity)) {
                $tier = $candidate;
            }
        }

        $unitPrice = $tier instanceof SkuPriceTier ? $tier->unit_price : $unit->base_price;
        $special = $this->specialPrice($unit, $context, $unitPrice);

        if ($special !== null) {
            [$price, $source, $promotion] = $special;

            return $this->resolved($unit, $price, null, $this->impliedDiscount($unit, $price), $quantity, $source, $promotion);
        }

        return $tier instanceof SkuPriceTier
            ? $this->resolved($unit, $tier->unit_price, $tier->min_quantity, $tier->discount_percent ?? $this->impliedDiscount($unit, $tier->unit_price), $quantity, PriceSource::Tier)
            : $this->resolved($unit, $unit->base_price, null, $this->baseDiscountPercent($unit), $quantity, PriceSource::Base);
    }

    /**
     * BR-PR14: what the storefront and the price list show this customer for the SKU (see ListedPrice). Without a
     * context, or a context that changes nothing for the SKU, it is the base price and the tiers as stored.
     */
    public function listed(SkuUnit $unit, ?PricingContext $context = null): ListedPrice
    {
        $negotiated = $context?->customerPrice($unit->id);

        if ($negotiated !== null) {
            return new ListedPrice($negotiated, PriceSource::Customer, $this->impliedDiscount($unit, $negotiated), []);
        }

        $tiers = $this->tiers($unit);
        $special = $this->specialPrice($unit, $context, $unit->base_price);

        if ($special !== null) {
            [$price, $source, $promotion] = $special;
            $below = array_values(array_filter($tiers, fn (TierData $tier): bool => Money::compare($tier->unitPrice ?? $unit->base_price, $price) < 0));

            return new ListedPrice($price, $source, $this->impliedDiscount($unit, $price), $below, $promotion);
        }

        return new ListedPrice($unit->base_price, PriceSource::Base, $this->baseDiscountPercent($unit), $tiers);
    }

    /**
     * The tiers as stored, lowest threshold first.
     *
     * @return list<TierData>
     */
    public function tiers(SkuUnit $unit): array
    {
        $tiers = [];

        foreach ($this->loadedTiers($unit)->sortBy('min_quantity') as $tier) {
            $tiers[] = new TierData($tier->min_quantity, $tier->unit_price, $tier->discount_percent);
        }

        return $tiers;
    }

    /**
     * The discount shown next to the base price (BR-PR10): the % it was entered as, else the % it
     * implies off the cover price, else null when the SKU has no cover price.
     */
    public function baseDiscountPercent(SkuUnit $unit): ?string
    {
        return $unit->base_price_percent ?? $this->impliedDiscount($unit, $unit->base_price);
    }

    /** BR-PR15: the price of the SKU under a promotion of `$percent` off its base price (the promotion preview). */
    public function promotionPrice(SkuUnit $unit, string $percent): string
    {
        return $this->percentOffBase($unit, $percent) ?? $unit->base_price;
    }

    private function resolved(SkuUnit $unit, string $unitPrice, ?int $tierMinQuantity, ?string $discount, int $quantity, PriceSource $source, ?PromotionPrice $promotion = null): ResolvedPrice
    {
        return new ResolvedPrice(
            unitPrice: $unitPrice,
            tierMinQuantity: $tierMinQuantity,
            discountPercent: $discount,
            coverPrice: $unit->cover_price,
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
    private function specialPrice(SkuUnit $unit, ?PricingContext $context, string $normal): ?array
    {
        $best = null;
        $group = $this->percentOffBase($unit, $context?->groupDiscount());

        if ($group !== null && Money::compare($group, $normal) < 0) {
            $best = [$group, PriceSource::Group, null];
        }

        $promotion = $context?->promotion($unit->sku_id);
        $promoted = $this->percentOffBase($unit, $promotion?->discountPercent);

        if ($promoted !== null && Money::compare($promoted, $best[0] ?? $normal) < 0) {
            return [$promoted, PriceSource::Promotion, $promotion];
        }

        return $best;
    }

    /** BR-PR12, BR-PR15, D27: the base price less a group's or a promotion's %, half-up to the piaster and never below 0.01; null without one. */
    private function percentOffBase(SkuUnit $unit, ?string $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        $price = $this->percent->priceFromPercent($unit->base_price, $percent);

        return Money::compare($price, '0.01') < 0 ? '0.01' : $price;
    }

    private function impliedDiscount(SkuUnit $unit, string $price): ?string
    {
        return $unit->cover_price === null ? null : $this->percent->percentOff($unit->cover_price, $price);
    }

    /**
     * @return Collection<int, SkuPriceTier>
     */
    private function loadedTiers(SkuUnit $unit): Collection
    {
        if (! $unit->relationLoaded('tiers')) {
            throw new LogicException('Load the SKU tiers before resolving its price; the resolver never queries.');
        }

        return $unit->tiers;
    }
}
