<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use App\Modules\Catalog\Services\FacetCacheVersion;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Storefront facets (BR-P08, D18): every active filterable field in `filter_order`, with its options
 * and the number of visible products each option would leave, given the *other* selected facets
 * (a facet never filters its own counts). Options with no products are left out.
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
        $definitions = ProductFieldDefinition::query()
            ->with('options')
            ->where('status', ArchiveStatus::Active)
            ->where('is_filterable', true)
            ->whereIn('type', [FieldType::Select, FieldType::Boolean])
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

        return $facets;
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
