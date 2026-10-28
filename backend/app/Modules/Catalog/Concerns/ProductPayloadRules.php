<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Concerns;

use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Pricing\Concerns\ReadsSkuPricing;
use App\Modules\Pricing\Data\TierData;
use App\Support\ArabicText;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Rules and typed accessors shared by the product create and update requests (08-api-contract.md §6).
 *
 * The SKUs come in one of two shapes (V2 Task 14, BR-P02, BR-P14): `sku` (a product without options, its one SKU: the V1 /
 * Task 13 shape, with the create-only top-level `opening_stock`), or `options[]` + `skus[]` (`options` at most 2, each
 * `{ id?, name, values: [{ id?, value, image_id? }] }` with 1..20 values, D92; `skus` each `{ id?, code, base_unit_id,
 * status?, option_values: [value text per option, in option order], units[], opening_stock? }`). A SKU is addressed by
 * its payload path ("sku" or "skus.{i}"), which also prefixes its validation errors.
 *
 * Each SKU is sold in `units[]` (V2 Task 13, D91): `{ id?, unit_id, conversion_factor, cover_price?, base_price |
 * base_price_percent, moq?, tiers?, is_default? }`, at most one flagged default (none = the first), each unit once per SKU.
 * Each unit's price set is read by Pricing's ReadsSkuPricing under its "{path}.units.{i}." prefix.
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

    /**
     * The payload paths of the SKUs sent, in their order: ["sku"] or ["skus.0", "skus.1", ...].
     *
     * @return list<string>
     */
    public function skuPaths(): array
    {
        if (! $this->has('skus')) {
            return ['sku'];
        }

        return array_map(fn (int|string $index): string => "skus.{$index}", array_keys($this->array('skus')));
    }

    public function skuId(string $path): ?int
    {
        return $this->filled("{$path}.id") ? $this->integer("{$path}.id") : null;
    }

    public function skuCode(string $path = 'sku'): string
    {
        return mb_trim($this->string("{$path}.code")->value());
    }

    public function skuInt(string $key, int $default = 0, string $path = 'sku'): int
    {
        return $this->filled("{$path}.{$key}") ? $this->integer("{$path}.{$key}") : $default;
    }

    public function skuStatus(string $path): SkuStatus
    {
        $status = $this->filled("{$path}.status") ? $this->enum("{$path}.status", SkuStatus::class) : null;

        return $status ?? SkuStatus::Active;
    }

    /**
     * @return list<string>
     */
    public function skuOptionValues(string $path): array
    {
        return array_values(array_map(fn (mixed $value): string => self::squish(is_scalar($value) ? (string) $value : ''), $this->array("{$path}.option_values")));
    }

    /**
     * The units sent for one SKU, in their order, each with the named arguments of its SkuPricingData.
     *
     * @return list<array{id: int|null, unitId: int, conversionFactor: int, isDefault: bool, pricing: array{basePrice: string|null, basePricePercent: string|null, moq: int, tiers: list<TierData>|null, coverPrice: string|null, coverPriceGiven: bool}}>
     */
    public function skuUnits(string $path = 'sku'): array
    {
        $units = [];

        foreach (array_keys($this->array("{$path}.units")) as $index) {
            $prefix = "{$path}.units.{$index}.";

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
     * The variant options sent (D92), names and values trimmed.
     *
     * @return list<array{id: int|null, name: string, values: list<array{id: int|null, value: string, imageId: int|null}>}>
     */
    public function productOptions(): array
    {
        $options = [];

        foreach (array_keys($this->array('options')) as $index) {
            $values = [];

            foreach (array_keys($this->array("options.{$index}.values")) as $valueIndex) {
                $prefix = "options.{$index}.values.{$valueIndex}.";
                $values[] = [
                    'id' => $this->filled("{$prefix}id") ? $this->integer("{$prefix}id") : null,
                    'value' => self::squish($this->string("{$prefix}value")->value()),
                    'imageId' => $this->filled("{$prefix}image_id") ? $this->integer("{$prefix}image_id") : null,
                ];
            }

            $options[] = [
                'id' => $this->filled("options.{$index}.id") ? $this->integer("options.{$index}.id") : null,
                'name' => self::squish($this->string("options.{$index}.name")->value()),
                'values' => $values,
            ];
        }

        return $options;
    }

    /**
     * D91: one default unit and each unit once per SKU. BR-P14 / D92: option names distinct, values distinct within their
     * option (compared folded, D32), every SKU names one known value of each option, a combination at most once, at least
     * one SKU active, and without options a single SKU.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->skuPaths() as $path) {
                $this->checkUnits($validator, $path);
            }

            $this->checkOptions($validator);

            if ($this->has('skus')) {
                $this->checkVariants($validator);
            }
        }];
    }

    protected function pricingPrefix(): string
    {
        return 'sku.units.*.';
    }

    /**
     * @return array<string, list<mixed>>
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
            'sku' => ['required_without:skus', 'prohibits:skus,options', 'array'],
            'sku.code' => ['exclude_without:sku', 'required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'sku.base_unit_id' => ['exclude_without:sku', 'required', 'integer'],
            'sku.units' => ['exclude_without:sku', 'required', 'array', 'min:1', 'max:10'],
            ...$this->unitRules('sku.units'),
            'options' => ['sometimes', 'array', 'max:2'],
            'options.*' => ['array'],
            'options.*.id' => ['sometimes', 'nullable', 'integer', 'min:1', 'distinct'],
            'options.*.name' => ['required', 'string', 'max:50'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:20'],
            'options.*.values.*' => ['array'],
            'options.*.values.*.id' => ['sometimes', 'nullable', 'integer', 'min:1', 'distinct'],
            'options.*.values.*.value' => ['required', 'string', 'max:50'],
            'options.*.values.*.image_id' => ['sometimes', 'nullable', 'integer'],
            'skus' => ['required_without:sku', 'array', 'min:1', 'max:400'],
            'skus.*' => ['array'],
            'skus.*.id' => ['sometimes', 'nullable', 'integer', 'min:1', 'distinct'],
            'skus.*.code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'distinct:ignore_case'],
            'skus.*.base_unit_id' => ['required', 'integer'],
            'skus.*.status' => ['sometimes', Rule::enum(SkuStatus::class)],
            'skus.*.option_values' => ['sometimes', 'array', 'max:2'],
            'skus.*.option_values.*' => ['required', 'string', 'max:50'],
            'skus.*.opening_stock' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'skus.*.units' => ['required', 'array', 'min:1', 'max:10'],
            ...$this->unitRules('skus.*.units'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function unitRules(string $units): array
    {
        return [
            "{$units}.*" => ['array'],
            "{$units}.*.id" => ['sometimes', 'nullable', 'integer', 'min:1', 'distinct'],
            "{$units}.*.unit_id" => ['required', 'integer'],
            "{$units}.*.conversion_factor" => ['required', 'integer', 'min:1', 'max:1000000'],
            "{$units}.*.is_default" => ['sometimes', 'boolean'],
            ...$this->pricingRules(prefix: "{$units}.*."),
        ];
    }

    private function checkUnits(Validator $validator, string $path): void
    {
        $units = array_filter($this->array("{$path}.units"), is_array(...));
        $defaults = array_filter($units, fn (array $unit): bool => filter_var($unit['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN));

        if (count($defaults) > 1) {
            $validator->errors()->add("{$path}.units", 'وحدة بيع افتراضية واحدة فقط');
        }

        $seen = [];

        foreach ($units as $index => $unit) {
            $unitId = $unit['unit_id'] ?? null;

            if (in_array($unitId, $seen, false)) {
                $validator->errors()->add("{$path}.units.{$index}.unit_id", 'الوحدة مكررة في هذا الصنف');
            }

            $seen[] = $unitId;
        }
    }

    private function checkOptions(Validator $validator): void
    {
        $names = [];

        foreach ($this->productOptions() as $index => $option) {
            $name = ArabicText::normalize($option['name']);

            if (in_array($name, $names, true)) {
                $validator->errors()->add("options.{$index}.name", 'اسم الخيار مكرر');
            }

            $names[] = $name;
            $values = [];

            foreach ($option['values'] as $valueIndex => $value) {
                $folded = ArabicText::normalize($value['value']);

                if (in_array($folded, $values, true)) {
                    $validator->errors()->add("options.{$index}.values.{$valueIndex}.value", 'القيمة مكررة في هذا الخيار');
                }

                $values[] = $folded;
            }
        }
    }

    private function checkVariants(Validator $validator): void
    {
        $options = $this->productOptions();
        $paths = $this->skuPaths();

        if ($options === [] && count($paths) > 1) {
            $validator->errors()->add('skus', 'المنتج بدون خيارات له صنف واحد فقط');

            return;
        }

        $combinations = [];
        $active = false;

        foreach ($paths as $path) {
            $active = $active || $this->skuStatus($path) === SkuStatus::Active;
            $combination = $this->combination($options, $this->skuOptionValues($path));

            if ($combination === null) {
                $validator->errors()->add("{$path}.option_values", 'اختر قيمة واحدة من كل خيار');
            } elseif (in_array($combination, $combinations, true)) {
                $validator->errors()->add("{$path}.option_values", 'هذه التركيبة مكررة');
            } else {
                $combinations[] = $combination;
            }
        }

        if (! $active) {
            $validator->errors()->add('skus', 'صنف واحد نشط على الأقل');
        }
    }

    /**
     * The folded combination key of a SKU's values, or null when they are not one known value of every option.
     *
     * @param  list<array{id: int|null, name: string, values: list<array{id: int|null, value: string, imageId: int|null}>}>  $options
     * @param  list<string>  $values
     */
    private function combination(array $options, array $values): ?string
    {
        if (count($values) !== count($options)) {
            return null;
        }

        $key = [];

        foreach ($options as $index => $option) {
            $folded = ArabicText::normalize($values[$index] ?? '');
            $known = array_map(fn (array $value): string => ArabicText::normalize($value['value']), $option['values']);

            if (! in_array($folded, $known, true)) {
                return null;
            }

            $key[] = $folded;
        }

        return implode('|', $key);
    }

    private static function squish(string $text): string
    {
        return mb_trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
