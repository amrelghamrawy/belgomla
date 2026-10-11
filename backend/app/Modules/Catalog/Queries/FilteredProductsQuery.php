<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared product selection (ProductFilterData) as an Eloquent builder, without visibility,
 * sorting or pagination; the store, dashboard, facet, price-list and bulk queries compose on it.
 *
 *  - category: that category and all its descendants via the materialized path (BR-P06),
 *  - f[key]: `custom_fields @> {key: value}` per field, AND-combined, GIN-indexed (BR-P08),
 *  - q: every word must appear in the folded name + SKU codes + select option labels (BR-P08, D32).
 */
final readonly class FilteredProductsQuery
{
    /**
     * Searchable text of a product: name, SKU codes and the labels of its select options.
     */
    private const string DOCUMENT = <<<'SQL'
        products.name
        || ' ' || coalesce((select string_agg(skus.code, ' ') from skus where skus.product_id = products.id), '')
        || ' ' || coalesce((
            select string_agg(o.label, ' ')
            from jsonb_each_text(products.custom_fields) as cf(key, value)
            join product_field_definitions d on d.tenant_id = products.tenant_id and d.key = cf.key and d.type = 'select'
            join product_field_options o on o.definition_id = d.id and o.id::text = cf.value
        ), '')
        SQL;

    /**
     * @return Builder<Product>
     */
    public function builder(ProductFilterData $filter): Builder
    {
        $query = Product::query();

        $this->category($query, $filter);
        $this->fields($query, $filter->fields);
        $this->search($query, $filter->q);

        if ($filter->status instanceof ProductStatus) {
            $query->where('products.status', $filter->status);
        }

        return $query;
    }

    /**
     * The category the selection points at (id on the dashboard, slug on the store), or null when the
     * selection has none or names an unknown one. FacetQuery uses it to pick the section's fields (D69).
     */
    public function resolveCategory(ProductFilterData $filter): ?Category
    {
        if ($filter->categoryId !== null) {
            return Category::query()->find($filter->categoryId);
        }

        if ($filter->categorySlug !== null) {
            return Category::query()->where('slug', $filter->categorySlug)->first();
        }

        return null;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function category(Builder $query, ProductFilterData $filter): void
    {
        if ($filter->categoryId === null && $filter->categorySlug === null) {
            return;
        }

        $category = $this->resolveCategory($filter);

        if (! $category instanceof Category) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('products.category_id', Category::query()->where('path', 'like', $category->path.'%')->select('id'));
    }

    /**
     * An unknown key or a value of the wrong type cannot match anything.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, string>  $fields
     */
    private function fields(Builder $query, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $definitions = ProductFieldDefinition::query()->whereIn('key', array_keys($fields))->get()->keyBy('key');

        foreach ($fields as $key => $raw) {
            $definition = $definitions->get($key);
            $value = $definition instanceof ProductFieldDefinition ? $this->value($definition->type, $raw) : null;

            if ($value === null) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $query->whereRaw('products.custom_fields @> ?::jsonb', [json_encode([$key => $value], JSON_THROW_ON_ERROR)]);
        }
    }

    private function value(FieldType $type, string $raw): int|float|bool|string|null
    {
        return match ($type) {
            FieldType::Select => filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            FieldType::Boolean => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            FieldType::Number => is_numeric($raw) ? $raw + 0 : null,
            FieldType::Text => $raw,
        };
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function search(Builder $query, ?string $q): void
    {
        $tokens = ArabicText::tokens($q ?? '');

        if ($tokens === []) {
            return;
        }

        $patterns = array_map(fn (string $token): string => '%'.addcslashes($token, '%_\\').'%', $tokens);
        $placeholders = implode(', ', array_fill(0, count($patterns), '?'));

        $query->whereRaw(ArabicText::sql('('.self::DOCUMENT.')')." like all (array[{$placeholders}])", $patterns);
    }
}
