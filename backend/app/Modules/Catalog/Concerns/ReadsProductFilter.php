<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Concerns;

use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Validation\Rule;

/**
 * Rules and parsing of the shared product filter (`category`, `f[key]`, `q`, `status`), optionally
 * nested under a prefix such as "filter." for bulk actions. The store identifies the category by
 * slug, the dashboard by id.
 */
trait ReadsProductFilter
{
    /**
     * @return array{categoryId: int|null, categorySlug: string|null, fields: array<string, string>, q: string|null, status: ProductStatus|null}
     */
    public function productFilterInput(): array
    {
        $prefix = $this->filterPrefix();
        $category = $this->input("{$prefix}category");
        $q = $this->input("{$prefix}q");
        $fields = [];

        foreach ((array) $this->input("{$prefix}f", []) as $key => $value) {
            if (is_scalar($value)) {
                $fields[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return [
            'categoryId' => ! $this->categoryBySlug() && is_numeric($category) ? (int) $category : null,
            'categorySlug' => $this->categoryBySlug() && is_string($category) && $category !== '' ? $category : null,
            'fields' => $fields,
            'q' => is_string($q) && mb_trim($q) !== '' ? mb_trim($q) : null,
            'status' => $this->has("{$prefix}status") ? $this->enum("{$prefix}status", ProductStatus::class) : null,
        ];
    }

    protected function filterPrefix(): string
    {
        return '';
    }

    protected function categoryBySlug(): bool
    {
        return false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function filterRules(): array
    {
        $prefix = $this->filterPrefix();

        return [
            "{$prefix}category" => $this->categoryBySlug() ? ['sometimes', 'string', 'max:120'] : ['sometimes', 'integer'],
            "{$prefix}q" => ['sometimes', 'nullable', 'string', 'max:100'],
            "{$prefix}status" => ['sometimes', Rule::enum(ProductStatus::class)],
            "{$prefix}f" => ['sometimes', 'array', 'max:20'],
            "{$prefix}f.*" => ['required', 'regex:/^[^\[\]{}]{1,50}$/u'],
        ];
    }
}
