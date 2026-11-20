<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use App\Modules\Catalog\Services\FacetCacheVersion;
use App\Modules\Tenancy\TenantContext;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * Storefront facets (BR-P08, D18): every active filterable field in `filter_order`, with its options
 * and the number of visible products each option would leave, given the *other* selected facets
 * (a facet never filters its own counts). Options with no products are left out. Inside a category,
 * only the fields of its section (and the section-less ones) are facets (D69).
 *
 * After the fields come the variant options (BR-P14, V2 Task 14): one facet per option name (folded, D32, so «اللون» of
 * every product is one facet), type `option`, key `o:<folded name>`, each value (folded, the id sent back as `f[key]`)
 * counting the visible products with an active SKU of that value. Labels are the spelling most products use.
 *
 * One grouped query per facet; the result is cached per tenant + selection and invalidated by bumping
 * the tenant's FacetCacheVersion on any product, field, option or category change (Tech §9b).
 *
 * @phpstan-type FacetOption array{id: int|string, label: string, count: int}
 * @phpstan-type Facet array{key: string, label: string, type: string, options: list<FacetOption>}
 */
final readonly class FacetQuery
{
    public function __construct(
        private FilteredProductsQuery $filtered,
        private FacetCacheVersion $version,
        private TenantContext $context,
    ) {}

    /**
     * @return list<Facet>
     */
    public function get(ProductFilterData $filter): array
    {
        $tenantId = $this->context->id();
        $key = sprintf('catalog:facets:%d:%d:%s', $tenantId, $this->version->current($tenantId), hash('xxh128', json_encode($filter, JSON_THROW_ON_ERROR)));

        return Cache::remember($key, config()->integer('catalog.facet_cache_seconds'), fn (): array => $this->compute($filter));
    }

    /**
     * @return list<Facet>
     */
    private function compute(ProductFilterData $filter): array
    {
        $category = $this->filtered->resolveCategory($filter);

        $definitions = ProductFieldDefinition::query()
            ->with('options')
            ->where('status', ArchiveStatus::Active)
            ->where('is_filterable', true)
            ->whereIn('type', [FieldType::Select, FieldType::Boolean])
            ->when($category instanceof Category, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->whereNull('kind')->orWhere('kind', $category?->kind),
            ))
            ->orderBy('filter_order')
            ->orderBy('id')
            ->get();

        $facets = [];

        foreach ($definitions as $definition) {
            $options = $this->options($definition, $this->counts($definition->key, $filter->without($definition->key)));

            if ($options !== []) {
                $facets[] = ['key' => $definition->key, 'label' => $definition->label, 'type' => $definition->type->value, 'options' => $options];
            }
        }

        return [...$facets, ...$this->optionFacets($filter)];
    }

    /**
     * @return list<Facet>
     */
    private function optionFacets(ProductFilterData $filter): array
    {
        $facets = [];
        $names = $this->optionRows($filter)
            ->selectRaw(ArabicText::sql('product_options.name').' as folded, mode() within group (order by product_options.name) as label')
            ->groupByRaw(ArabicText::sql('product_options.name'))
            ->orderByRaw('min(product_options.name)')
            ->get();

        foreach ($names as $name) {
            $folded = is_string($name->folded) ? $name->folded : '';
            $key = FilteredProductsQuery::OPTION_PREFIX.$folded;
            $options = [];

            $values = $this->optionRows($filter->without($key))
                ->selectRaw(ArabicText::sql('product_option_values.value').' as id, mode() within group (order by product_option_values.value) as label, count(distinct products.id) as total')
                ->whereRaw(ArabicText::sql('product_options.name').' = ?', [$folded])
                ->groupByRaw(ArabicText::sql('product_option_values.value'))
                ->orderByRaw('min(product_option_values.sort_order), 2')
                ->get();

            foreach ($values as $value) {
                if (is_string($value->id) && is_string($value->label) && is_numeric($value->total)) {
                    $options[] = ['id' => $value->id, 'label' => $value->label, 'count' => (int) $value->total];
                }
            }

            if ($options !== []) {
                $facets[] = ['key' => $key, 'label' => is_string($name->label) ? $name->label : $folded, 'type' => 'option', 'options' => $options];
            }
        }

        return $facets;
    }

    /** The selection's visible products joined to the option values of their active SKUs. */
    private function optionRows(ProductFilterData $filter): QueryBuilder
    {
        return $this->filtered->builder($filter)
            ->tap(new VisibleOnStore)
            ->reorder()
            ->toBase()
            ->join('skus', 'skus.product_id', '=', 'products.id')
            ->join('sku_option_values', 'sku_option_values.sku_id', '=', 'skus.id')
            ->join('product_options', 'product_options.id', '=', 'sku_option_values.product_option_id')
            ->join('product_option_values', 'product_option_values.id', '=', 'sku_option_values.product_option_value_id')
            ->where('skus.status', 'active');
    }

    /**
     * Visible products per stored value of the field, e.g. ["12" => 48, "13" => 20] or ["true" => 5].
     *
     * @return array<array-key, int>
     */
    private function counts(string $key, ProductFilterData $filter): array
    {
        // Keys are validated as [a-z][a-z0-9_]* when a field is created; anything else is never interpolated.
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            return [];
        }

        $rows = $this->filtered->builder($filter)
            ->tap(new VisibleOnStore)
            ->reorder()
            ->toBase()
            ->selectRaw("products.custom_fields ->> '{$key}' as value, count(*) as total")
            ->whereRaw("products.custom_fields ->> '{$key}' is not null")
            ->groupByRaw("products.custom_fields ->> '{$key}'")
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            if (is_scalar($row->value) && is_numeric($row->total)) {
                $counts[(string) $row->value] = (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * @param  array<array-key, int>  $counts  stored value => products (numeric keys become ints in PHP)
     * @return list<FacetOption>
     */
    private function options(ProductFieldDefinition $definition, array $counts): array
    {
        $candidates = $definition->type === FieldType::Boolean
            ? [['id' => 'true', 'label' => 'نعم'], ['id' => 'false', 'label' => 'لا']]
            : $definition->options
                ->filter(fn (ProductFieldOption $option): bool => $option->status === ArchiveStatus::Active)
                ->map(fn (ProductFieldOption $option): array => ['id' => $option->id, 'label' => $option->label])
                ->values()
                ->all();

        $options = [];

        foreach ($candidates as $candidate) {
            $count = $counts[$candidate['id']] ?? 0;

            if ($count > 0) {
                $options[] = ['id' => $candidate['id'], 'label' => $candidate['label'], 'count' => $count];
            }
        }

        return $options;
    }
}
