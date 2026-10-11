<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Exceptions\InvalidTiers;
use App\Modules\Pricing\Exceptions\MissingCoverPrice;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Turns entered pricing into the concrete values a SKU stores, and checks them, without writing:
 * percentages become absolute prices off the reference price (`cover_price`: the cover price of a book,
 * the retail price otherwise, BR-PR10), thresholds are unique (BR-PR05),
 * every price is above zero (BR-PR01, BR-PR05) and no tier costs more than the base price (D28).
 * UpdateSkuPricing writes the plan; bulk preview only plans.
 *
 * @phpstan-type TierState array{min_quantity: int, unit_price: string, discount_percent: string|null}
 * @phpstan-type PricingState array{cover_price: string|null, base_price: string, base_price_percent: string|null, moq: int, tiers: list<TierState>}
 */
final readonly class SkuPricePlanner
{
    public function __construct(private PercentPricing $percent) {}

    /**
     * What the SKU stores now. A SKU not saved yet has no tiers.
     *
     * @return PricingState
     */
    public function current(Sku $sku): array
    {
        $tiers = [];

        if ($sku->exists) {
            foreach ($sku->loadMissing('tiers')->tiers->sortBy('min_quantity') as $tier) {
                $tiers[] = ['min_quantity' => $tier->min_quantity, 'unit_price' => $tier->unit_price, 'discount_percent' => $tier->discount_percent];
            }
        }

        return [
            'cover_price' => $sku->cover_price,
            'base_price' => $sku->base_price,
            'base_price_percent' => $sku->base_price_percent,
            'moq' => $sku->moq,
            'tiers' => $tiers,
        ];
    }

    /**
     * What the SKU will store once `$data` is applied.
     *
     * @return PricingState
     */
    public function plan(Sku $sku, SkuPricingData $data): array
    {
        $cover = $data->coverPriceGiven ? $data->coverPrice : $sku->cover_price;
        $basePrice = $data->basePrice ?? $this->fromPercent($sku, $cover, $data->basePricePercent);

        if (Money::compare($basePrice, '0') <= 0) {
            throw InvalidPricingInput::basePriceNotPositive();
        }

        $tiers = $data->tiers === null
            ? ($sku->exists ? $this->current($sku)['tiers'] : [])
            : array_map(fn (TierData $tier): array => [
                'min_quantity' => $tier->minQuantity,
                'unit_price' => $tier->unitPrice ?? $this->fromPercent($sku, $cover, $tier->discountPercent),
                'discount_percent' => $tier->discountPercent,
            ], $data->tiers);

        $this->check($tiers, $basePrice);
        usort($tiers, fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);

        return [
            'cover_price' => $cover,
            'base_price' => $basePrice,
            'base_price_percent' => $data->basePricePercent,
            'moq' => $data->moq,
            'tiers' => $tiers,
        ];
    }

    /**
     * The section kind is read only on the failure path, through the SKU's product and category
     * (a relation walk, not a Catalog model dependency), so the message names the price correctly (D68).
     */
    private function fromPercent(Sku $sku, ?string $cover, ?string $percent): string
    {
        if ($percent === null) {
            throw new InvalidArgumentException('A price needs an absolute amount or a discount percent.');
        }

        if ($cover === null) {
            throw MissingCoverPrice::for($sku->product->category->kind);
        }

        return $this->percent->priceFromPercent($cover, $percent);
    }

    /**
     * @param  list<TierState>  $tiers
     */
    private function check(array $tiers, string $basePrice): void
    {
        $problems = [];
        $seen = [];

        foreach ($tiers as $tier) {
            $min = $tier['min_quantity'];

            if (isset($seen[$min])) {
                $problems[] = InvalidTiers::duplicateThreshold($min);
            }

            if (Money::compare($tier['unit_price'], '0') <= 0) {
                $problems[] = InvalidTiers::notPositive($min);
            } elseif (Money::compare($tier['unit_price'], $basePrice) > 0) {
                $problems[] = InvalidTiers::aboveBasePrice($min);
            }

            $seen[$min] = true;
        }

        if ($problems !== []) {
            throw InvalidTiers::withProblems(array_values(array_unique($problems)));
        }
    }
}
