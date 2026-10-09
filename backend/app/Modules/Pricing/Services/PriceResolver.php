<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\ResolvedPrice;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * The single price authority (07-domain-model.md §4), used by the storefront, the cart and checkout
 * (BR-PR06). Pure: it reads the SKU and its already-loaded `tiers`, never the database.
 *
 * BR-PR04: the tier with the highest `min_quantity ≤ quantity` wins, else the base price. MOQ plays no
 * part here (BR-MOQ01 is a separate checkout assertion). Tiers are per SKU only (BR-PR02).
 */
final readonly class PriceResolver
{
    public function __construct(private PercentPricing $percent) {}

    public function resolve(Sku $sku, int $quantity): ResolvedPrice
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("A quantity is a whole number of at least 1 (BR-U05), {$quantity} given.");
        }

        $tier = null;

        foreach ($this->loadedTiers($sku) as $candidate) {
            if ($candidate->min_quantity <= $quantity && (! $tier instanceof SkuPriceTier || $candidate->min_quantity > $tier->min_quantity)) {
                $tier = $candidate;
            }
        }

        $unitPrice = $tier instanceof SkuPriceTier ? $tier->unit_price : $sku->base_price;
        $discount = $tier instanceof SkuPriceTier
            ? $tier->discount_percent ?? $this->impliedDiscount($sku, $unitPrice)
            : $this->baseDiscountPercent($sku);

        return new ResolvedPrice(
            unitPrice: $unitPrice,
            tierMinQuantity: $tier?->min_quantity,
            discountPercent: $discount,
            coverPrice: $sku->cover_price,
            lineTotal: Money::times($unitPrice, $quantity),
        );
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
