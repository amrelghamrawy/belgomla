<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;

/**
 * BR-P14, D92: the variants a product's options make. Every combination of one value per option (the cartesian product,
 * in option and value order) is one SKU draft with the code `{base}-{slug of each value}`. A value's slug is its ASCII
 * letters and digits upper-cased, any other run becoming one "-"; a value with nothing ASCII in it (an Arabic «أزرق») is
 * slugged as its 1-based position in its option, so codes stay inside the SKU code alphabet (BR-P03). The dashboard
 * editor generates the same codes (frontend `lib/dashboard/variants.ts`). The label is the values joined by " / ".
 */
final readonly class VariantGenerator
{
    /**
     * @param  list<ProductOptionData>  $options
     * @return list<array{option_values: list<string>, code: string, label: string|null}>
     */
    public function generate(string $base, array $options): array
    {
        $drafts = [];

        foreach ($this->combinations($options) as $positions) {
            $values = [];
            $slugs = [];

            foreach ($positions as $optionIndex => $position) {
                $value = $options[$optionIndex]->values[$position]->value;
                $values[] = $value;
                $slugs[] = $this->slug($value, $position + 1);
            }

            $drafts[] = ['option_values' => $values, 'code' => implode('-', [$base, ...$slugs]), 'label' => $this->label($values)];
        }

        return $drafts;
    }

    /**
     * The variant label («أزرق / M»), null for a SKU without option values.
     *
     * @param  list<string>  $values
     */
    public function label(array $values): ?string
    {
        return $values === [] ? null : implode(' / ', $values);
    }

    public function slug(string $value, int $position): string
    {
        $slug = mb_trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper($value)), '-');

        return $slug === '' ? (string) $position : $slug;
    }

    /**
     * Value positions per option for every combination, the last option varying fastest. No options = one empty combination.
     *
     * @param  list<ProductOptionData>  $options
     * @return list<list<int>>
     */
    private function combinations(array $options): array
    {
        $combinations = [[]];

        foreach ($options as $option) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach (array_keys(array_map(fn (ProductOptionValueData $value): string => $value->value, $option->values)) as $position) {
                    $next[] = [...$combination, $position];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }
}
