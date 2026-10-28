<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Concerns;

use App\Modules\Pricing\Concerns\ReadsSkuPricing;
use App\Modules\Pricing\Data\TierData;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Rules and typed accessors shared by the product create and update requests (08-api-contract.md §6).
 * The SKU is sold in `sku.units[]` (V2 Task 13, D91): `{ id?, unit_id, conversion_factor, cover_price?, base_price |
 * base_price_percent, moq?, tiers?, is_default? }`, at most one flagged default (none = the first), each unit at most once.
 * Each unit's price set is read by Pricing's ReadsSkuPricing under its "sku.units.{i}." prefix. `opening_stock` is create-only (StoreProductRequest).
 * `preorder { enabled, expected_at?, limit? }` is optional on both (D88).
 */
trait ProductPayloadRules
{
    use ReadsSkuPricing;

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    public function categoryId(): int
    {
        return $this->integer('category_id');
    }

    public function description(): ?string
    {
        $description = $this->input('description');

        return is_string($description) && mb_trim($description) !== '' ? mb_trim($description) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function customFields(): array
    {
        $fields = $this->input('custom_fields', []);

        return is_array($fields) ? $fields : [];
    }

    public function hasPreorder(): bool
    {
        return $this->has('preorder');
    }

    public function preorderEnabled(): bool
    {
        return $this->boolean('preorder.enabled');
    }

    public function preorderExpectedAt(): ?CarbonImmutable
    {
        return $this->filled('preorder.expected_at') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('preorder.expected_at')->value(), 'UTC') ?: null : null;
    }

    public function preorderLimit(): ?int
    {
        return $this->filled('preorder.limit') ? $this->integer('preorder.limit') : null;
    }

    public function skuCode(): string
    {
        return mb_trim($this->string('sku.code')->value());
    }

    public function skuInt(string $key, int $default = 0): int
    {
        return $this->filled("sku.{$key}") ? $this->integer("sku.{$key}") : $default;
    }

    /**
     * The units sent, in their order, each with the named arguments of its SkuPricingData.
     *
     * @return list<array{id: int|null, unitId: int, conversionFactor: int, isDefault: bool, pricing: array{basePrice: string|null, basePricePercent: string|null, moq: int, tiers: list<TierData>|null, coverPrice: string|null, coverPriceGiven: bool}}>
     */
    public function skuUnits(): array
    {
        $units = [];

        foreach (array_keys($this->array('sku.units')) as $index) {
            $prefix = "sku.units.{$index}.";

            $units[] = [
                'id' => $this->filled("{$prefix}id") ? $this->integer("{$prefix}id") : null,
                'unitId' => $this->integer("{$prefix}unit_id"),
                'conversionFactor' => $this->integer("{$prefix}conversion_factor", 1),
                'isDefault' => $this->boolean("{$prefix}is_default"),
                'pricing' => $this->pricingInput($prefix),
            ];
        }

        return $units;
    }

    /**
     * D91: one default unit at most.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $defaults = array_filter($this->array('sku.units'), fn (mixed $unit): bool => is_array($unit) && filter_var($unit['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN));

            if (count($defaults) > 1) {
                $validator->errors()->add('sku.units', 'وحدة بيع افتراضية واحدة فقط');
            }
        }];
    }

    protected function pricingPrefix(): string
    {
        return 'sku.units.*.';
    }

    /**
     * @return array<string, list<string>>
     */
    protected function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'custom_fields' => ['sometimes', 'array'],
            'preorder' => ['sometimes', 'array'],
            'preorder.enabled' => ['required_with:preorder', 'boolean'],
            'preorder.expected_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'preorder.limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'sku' => ['required', 'array'],
            'sku.code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'sku.base_unit_id' => ['required', 'integer'],
            'sku.units' => ['required', 'array', 'min:1', 'max:10'],
            'sku.units.*' => ['array'],
            'sku.units.*.id' => ['sometimes', 'nullable', 'integer', 'min:1', 'distinct'],
            'sku.units.*.unit_id' => ['required', 'integer', 'distinct'],
            'sku.units.*.conversion_factor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'sku.units.*.is_default' => ['sometimes', 'boolean'],
            ...$this->pricingRules(),
        ];
    }
}
