<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Concerns;

use App\Modules\Pricing\Data\TierData;
use App\Support\Money;

/**
 * Rules and parsing of a SKU price set (08-api-contract.md §6), optionally nested under a prefix such as
 * "sku." in the product create/update body. One place for these rules, used by the pricing endpoint and
 * the Catalog product requests alike.
 *
 * BR-PR10: `base_price` or `base_price_percent` is required; when the percent is sent it wins and the
 * price is derived from the cover price. Same per tier with `unit_price` / `discount_percent`.
 * Cross-row checks (duplicate thresholds, D28, cover price present) are the planner's, not the request's.
 */
trait ReadsSkuPricing
{
    /**
     * Named arguments of SkuPricingData.
     *
     * @return array{basePrice: string|null, basePricePercent: string|null, moq: int, tiers: list<TierData>|null, coverPrice: string|null, coverPriceGiven: bool}
     */
    public function pricingInput(): array
    {
        $prefix = $this->pricingPrefix();
        $percent = $this->moneyInput($this->input("{$prefix}base_price_percent"));
        $moq = $this->input("{$prefix}moq");

        return [
            'basePrice' => $percent === null ? $this->moneyInput($this->input("{$prefix}base_price")) : null,
            'basePricePercent' => $percent,
            'moq' => is_numeric($moq) ? (int) $moq : 1,
            'tiers' => $this->tierInput(),
            'coverPrice' => $this->moneyInput($this->input("{$prefix}cover_price")),
            'coverPriceGiven' => ! $this->keepsCoverPriceWhenAbsent() || $this->has("{$prefix}cover_price"),
        ];
    }

    /**
     * The tiers sent, or null when the key is absent (the SKU keeps its tiers).
     *
     * @return list<TierData>|null
     */
    public function tierInput(): ?array
    {
        $key = $this->pricingPrefix().'tiers';

        if (! $this->has($key)) {
            return null;
        }

        $tiers = [];

        foreach ((array) $this->input($key, []) as $row) {
            $row = is_array($row) ? $row : [];
            $percent = $this->moneyInput($row['discount_percent'] ?? null);
            $min = $row['min_quantity'] ?? null;

            $tiers[] = new TierData(
                minQuantity: is_numeric($min) ? (int) $min : 0,
                unitPrice: $percent === null ? $this->moneyInput($row['unit_price'] ?? null) : null,
                discountPercent: $percent,
            );
        }

        return $tiers;
    }

    protected function pricingPrefix(): string
    {
        return '';
    }

    /** The pricing endpoint may leave the cover price out to keep it; the product body always carries it. */
    protected function keepsCoverPriceWhenAbsent(): bool
    {
        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function pricingRules(bool $moqRequired = false, bool $tiersRequired = false): array
    {
        $prefix = $this->pricingPrefix();

        return [
            "{$prefix}cover_price" => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            "{$prefix}base_price" => ["required_without:{$prefix}base_price_percent", 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            "{$prefix}base_price_percent" => ['nullable', 'decimal:0,2', 'gt:0', 'lt:100'],
            "{$prefix}moq" => [$moqRequired ? 'required' : 'sometimes', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}tiers" => [$tiersRequired ? 'present' : 'sometimes', 'array', 'max:20'],
            "{$prefix}tiers.*" => ['array'],
            "{$prefix}tiers.*.min_quantity" => ['required', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}tiers.*.unit_price" => ["required_without:{$prefix}tiers.*.discount_percent", 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            "{$prefix}tiers.*.discount_percent" => ['nullable', 'decimal:0,2', 'gt:0', 'lt:100'],
        ];
    }

    private function moneyInput(mixed $value): ?string
    {
        return is_int($value) || is_float($value) || (is_string($value) && $value !== '')
            ? Money::normalize((string) $value)
            : null;
    }
}
