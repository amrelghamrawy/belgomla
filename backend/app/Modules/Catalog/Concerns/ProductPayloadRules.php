<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Concerns;

use App\Modules\Pricing\Concerns\ReadsSkuPricing;

/**
 * Rules and typed accessors shared by the product create and update requests (08-api-contract.md §6).
 * The SKU price set (`sku.cover_price`, `sku.base_price[_percent]`, `sku.moq`, `sku.tiers`) is read by
 * Pricing's ReadsSkuPricing under the "sku." prefix. `opening_stock` (Task 3) is not read here.
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

    public function skuCode(): string
    {
        return mb_trim($this->string('sku.code')->value());
    }

    public function skuInt(string $key, int $default = 0): int
    {
        return $this->filled("sku.{$key}") ? $this->integer("sku.{$key}") : $default;
    }

    protected function pricingPrefix(): string
    {
        return 'sku.';
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
            'sku' => ['required', 'array'],
            'sku.code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'sku.selling_unit_id' => ['required', 'integer'],
            'sku.base_unit_id' => ['required', 'integer'],
            'sku.conversion_factor' => ['required', 'integer', 'min:1', 'max:1000000'],
            ...$this->pricingRules(),
        ];
    }
}
