<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Concerns;

use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Support\Money;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Rules and typed accessors shared by the bundle create and update requests (08-api-contract.md §6, BR-P15, D93):
 * `{ name, category_id, description?, custom_fields?, code, unit_id, moq?, components: [{ sku_unit_id, quantity }] (1..50,
 * each unit once), pricing: { mode: fixed | percent_off_sum, value } }`. A fixed price is at least 0.01; a % is 0 or more
 * and below 100. Whether the components are units of active simple products is checked against the store's data by the
 * action (BundleComponents).
 */
trait BundlePayloadRules
{
    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    public function categoryId(): int
    {
        return $this->integer('category_id');
    }

    public function code(): string
    {
        return mb_trim($this->string('code')->value());
    }

    public function unitId(): int
    {
        return $this->integer('unit_id');
    }

    public function moq(): int
    {
        return $this->filled('moq') ? $this->integer('moq') : 1;
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

    /**
     * @return non-empty-list<BundleComponentData>
     */
    public function components(): array
    {
        $components = [];

        foreach (array_values(array_filter($this->array('components'), is_array(...))) as $component) {
            $components[] = new BundleComponentData($this->intOf($component['sku_unit_id'] ?? 0), $this->intOf($component['quantity'] ?? 1));
        }

        return $components !== [] ? $components : throw new InvalidArgumentException('A bundle has at least one component (the request rules require one).');
    }

    public function pricing(): BundlePricingData
    {
        return new BundlePricingData($this->enum('pricing.mode', BundlePriceMode::class) ?? BundlePriceMode::Fixed, $this->pricingValue());
    }

    /**
     * A fixed price is at least 0.01, a % below 100.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mode = $this->enum('pricing.mode', BundlePriceMode::class);
            $value = $this->pricingValue();

            if ($mode === BundlePriceMode::Fixed && Money::compare($value, '0.01') < 0) {
                $validator->errors()->add('pricing.value', 'سعر الباقة لا يقل عن 0.01');
            }

            if ($mode === BundlePriceMode::PercentOffSum && Money::compare($value, '100.00') >= 0) {
                $validator->errors()->add('pricing.value', 'نسبة الخصم أقل من 100%');
            }
        }];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function bundleRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:5000'],
            'custom_fields' => ['sometimes', 'array'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'unit_id' => ['required', 'integer'],
            'moq' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'components' => ['required', 'array', 'min:1', 'max:50'],
            'components.*' => ['array'],
            'components.*.sku_unit_id' => ['required', 'integer', 'min:1', 'distinct'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'pricing' => ['required', 'array'],
            'pricing.mode' => ['required', Rule::enum(BundlePriceMode::class)],
            'pricing.value' => ['required', 'decimal:0,2', 'min:0', 'max:9999999.99'],
        ];
    }

    private function pricingValue(): string
    {
        $value = $this->input('pricing.value');

        return Money::normalize(is_numeric($value) ? (string) $value : '0');
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
