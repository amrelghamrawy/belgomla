<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Exceptions\BundleRefused;
use App\Modules\Catalog\Exceptions\InvalidCustomFields;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POST /api/dashboard/bundles/generate (BR-P15, D93 g): a full-grade set in one step. The product list's filter (e.g.
 * grade + term + publisher; not empty) selects the **active simple** products; each brings its first active SKU's
 * default unit, quantity 1, in name order (at most 50). The set is created through CreateBundle as an **inactive** draft
 * the merchant reviews: named «طقم» + the facet labels of the selection (category, then the custom field values in field
 * order), coded `SET-{n}` (the first free number), sold in the store unit «طقم» when there is one (else the first
 * component's unit), in the selected category (else the first component's), with the custom field values every
 * component shares, at the components' sum (percent_off_sum 0%).
 */
final readonly class GenerateGradeSet
{
    public const int MAX_COMPONENTS = 50;

    public function __construct(
        private FilteredProductsQuery $filtered,
        private CustomFieldValidator $fields,
        private CreateBundle $create,
    ) {}

    public function handle(ProductFilterData $filter, ?int $createdBy = null): Product
    {
        if ($filter->isEmpty()) {
            throw BundleRefused::emptyFilter();
        }

        $units = $this->componentUnits($filter);
        $first = $units->first() ?? throw BundleRefused::nothingSelected();
        $components = array_values($units->map(fn (SkuUnit $unit): BundleComponentData => new BundleComponentData($unit->id, 1))->all()) ?: throw BundleRefused::nothingSelected();
        $category = ($filter->categoryId !== null ? Category::query()->find($filter->categoryId) : null)
            ?? ($filter->categorySlug !== null ? Category::query()->where('slug', $filter->categorySlug)->first() : null)
            ?? $first->sku->product->category;

        return $this->create->handle(new BundleData(
            name: $this->name($filter, $category),
            categoryId: $category->id,
            code: $this->code(),
            unitId: $this->unitId($first),
            components: $components,
            pricing: new BundlePricingData(BundlePriceMode::PercentOffSum, '0.00'),
            customFields: $this->sharedFields($units, $category),
            status: ProductStatus::Inactive,
        ), $createdBy);
    }

    /**
     * @return Collection<int, SkuUnit>
     */
    private function componentUnits(ProductFilterData $filter): Collection
    {
        $products = $this->filtered->builder($filter)
            ->where('products.status', ProductStatus::Active)
            ->where('products.type', ProductType::Simple)
            ->whereHas('activeSkus', fn (Builder $skus): Builder => $skus->whereHas('defaultUnit', fn (Builder $unit): Builder => $unit->where('status', 'active')))
            ->with(['category', 'activeSkus' => fn (HasMany $skus): HasMany => $skus->with('defaultUnit')])
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->limit(self::MAX_COMPONENTS + 1)
            ->get();

        if ($products->count() > self::MAX_COMPONENTS) {
            throw BundleRefused::tooManySelected(self::MAX_COMPONENTS);
        }

        $units = new Collection;

        foreach ($products as $product) {
            $sku = $product->activeSkus->first();
            $unit = $sku instanceof Sku ? $sku->defaultUnit : null;

            if ($unit instanceof SkuUnit) {
                $units->push($unit->setRelation('sku', $sku->setRelation('product', $product)));
            }
        }

        return $units;
    }

    /** «طقم» + the selection's labels: the category, then each custom field value in field order. */
    private function name(ProductFilterData $filter, Category $category): string
    {
        $labels = $filter->categoryId !== null || $filter->categorySlug !== null ? [$category->name] : [];
        $definitions = ProductFieldDefinition::query()->with('options')->whereIn('key', array_keys($filter->fields))->orderBy('sort_order')->orderBy('id')->get();

        foreach ($definitions as $definition) {
            $value = $filter->fields[$definition->key] ?? '';
            $labels[] = match ($definition->type) {
                FieldType::Select => $definition->options->first(fn (ProductFieldOption $option): bool => (string) $option->id === $value)->label ?? $value,
                FieldType::Boolean => $value === 'true' ? $definition->label : "{$definition->label}: لا",
                FieldType::Number, FieldType::Text => $value,
            };
        }

        if ($filter->q !== null) {
            $labels[] = $filter->q;
        }

        return mb_substr('طقم '.implode(' - ', array_filter($labels, fn (string $label): bool => $label !== '')), 0, 200);
    }

    /** `SET-{n}`, the first number not taken by a SKU code. */
    private function code(): string
    {
        $taken = Sku::query()->where('code', 'like', 'SET-%')->pluck('code')->all();
        $number = Product::query()->where('type', ProductType::Bundle)->count() + 1;

        while (in_array("SET-{$number}", $taken, true)) {
            $number++;
        }

        return "SET-{$number}";
    }

    private function unitId(SkuUnit $first): int
    {
        $set = Unit::query()->where('status', 'active')->get()->first(fn (Unit $unit): bool => ArabicText::normalize($unit->name) === ArabicText::normalize('طقم'));

        return $set->id ?? $first->unit_id;
    }

    /**
     * The custom field values every component has alike, when they are valid for the set's category.
     *
     * @param  Collection<int, SkuUnit>  $units
     * @return array<string, int|float|string|bool>
     */
    private function sharedFields(Collection $units, Category $category): array
    {
        $shared = null;

        foreach ($units as $unit) {
            $fields = $unit->sku->product->custom_fields;
            $shared = $shared === null ? $fields : array_filter($shared, fn (int|float|string|bool $value, string $key): bool => array_key_exists($key, $fields) && $fields[$key] === $value, ARRAY_FILTER_USE_BOTH);
        }

        try {
            return $this->fields->validate($shared ?? [], $category->section_id, required: false);
        } catch (InvalidCustomFields) {
            return [];
        }
    }
}
