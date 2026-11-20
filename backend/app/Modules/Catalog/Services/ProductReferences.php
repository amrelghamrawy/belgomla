<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Collection;

/**
 * Checks the ids a product payload points at, within the tenant in context (BR-T04): the category (D25: required), each
 * SKU's base unit and every unit sold in (D91), each SKU's id (one of the product's), the option and value ids and the
 * swatch images (the product's own, BR-P14), and the SKU codes (BR-P03: unique per tenant). Archived categories and units
 * cannot be newly chosen, but a product may keep the ones it already has.
 *
 * `matches()` pairs every SKU sent with the product's SKU it stands for (V2 Task 14): by id, else the SKU with the same
 * code, else the one with the same combination of option values (compared folded, D32); null = a new SKU.
 */
final readonly class ProductReferences
{
    /** Returns the (new) category so callers can read its kind (D69). */
    public function check(ProductData $data, ?Product $existing = null): Category
    {
        $category = $this->category($data->categoryId, $existing?->category_id);
        $matches = $this->matches($data, $existing);

        foreach ($data->skus as $index => $sku) {
            $current = $matches[$index];
            $this->unit("{$sku->path}.base_unit_id", $sku->baseUnitId, $current?->base_unit_id);
            $this->units($sku, $current);
        }

        $this->options($data, $existing);
        $this->codes($data, $matches);

        return $category;
    }

    /**
     * The product's SKU each SKU sent stands for, by index of `$data->skus` (null = new).
     *
     * @return array<int, Sku|null>
     */
    public function matches(ProductData $data, ?Product $existing): array
    {
        $skus = $existing instanceof Product ? Sku::query()->with('optionValues.option')->where('product_id', $existing->id)->get() : new Collection;
        $named = array_filter(array_map(fn (SkuData $sku): ?int => $sku->id, $data->skus));
        $taken = [];
        $matches = [];

        foreach ($data->skus as $index => $sku) {
            if ($sku->id !== null) {
                $match = $skus->firstWhere('id', $sku->id) ?? throw InvalidCatalogInput::unknownReference("{$sku->path}.id");
            } else {
                $free = $skus->reject(fn (Sku $candidate): bool => in_array($candidate->id, [...$named, ...$taken], true));
                $match = $free->firstWhere('code', $sku->code)
                    ?? ($sku->optionValues === [] && $skus->count() === 1 && $data->skus === [$sku] ? $free->first() : null)
                    ?? $free->first(fn (Sku $candidate): bool => $sku->optionValues !== [] && $this->combination($candidate) === $this->folded($sku->optionValues));
            }

            $matches[$index] = $match;

            if ($match instanceof Sku) {
                $taken[] = $match->id;
            }
        }

        return $matches;
    }

    private function category(int $id, ?int $current): Category
    {
        $category = Category::query()->find($id) ?? throw InvalidCatalogInput::unknownReference('category_id');

        if ($category->status === ArchiveStatus::Archived && $id !== $current) {
            throw InvalidCatalogInput::archivedReference('category_id');
        }

        return $category;
    }

    /**
     * D91: every unit sold in exists in the store (an archived one only when the SKU already sells in it), and a unit sent
     * with its id is one of this SKU's.
     */
    private function units(SkuData $data, ?Sku $sku): void
    {
        $current = $sku instanceof Sku ? SkuUnit::query()->where('sku_id', $sku->id)->get(['id', 'unit_id', 'status']) : new Collection;

        foreach ($data->units as $index => $unit) {
            if ($unit->id !== null && ! $current->contains('id', $unit->id)) {
                throw InvalidCatalogInput::unknownReference("{$data->path}.units.{$index}.id");
            }

            $sold = $current->first(fn (SkuUnit $skuUnit): bool => $skuUnit->unit_id === $unit->unitId && $skuUnit->isActive());
            $this->unit("{$data->path}.units.{$index}.unit_id", $unit->unitId, $sold?->unit_id);
        }
    }

    private function unit(string $field, int $id, ?int $current): void
    {
        $unit = Unit::query()->find($id) ?? throw InvalidCatalogInput::unknownReference($field);

        if ($unit->status === ArchiveStatus::Archived && $id !== $current) {
            throw InvalidCatalogInput::archivedReference($field);
        }
    }

    /** BR-P14: option and value ids are the product's own; a swatch is one of the product's images. */
    private function options(ProductData $data, ?Product $existing): void
    {
        $optionIds = $existing instanceof Product ? $existing->options()->pluck('id')->all() : [];
        $valueIds = ProductOptionValue::query()->whereIn('product_option_id', $optionIds)->pluck('id')->all();
        $imageIds = $existing instanceof Product ? ProductImage::query()->where('product_id', $existing->id)->pluck('id')->all() : [];

        foreach ($data->options as $index => $option) {
            if ($option->id !== null && ! in_array($option->id, $optionIds, true)) {
                throw InvalidCatalogInput::unknownReference("options.{$index}.id");
            }

            foreach ($option->values as $valueIndex => $value) {
                if ($value->id !== null && ! in_array($value->id, $valueIds, true)) {
                    throw InvalidCatalogInput::unknownReference("options.{$index}.values.{$valueIndex}.id");
                }

                if ($value->imageId !== null && ! in_array($value->imageId, $imageIds, true)) {
                    throw InvalidCatalogInput::unknownReference("options.{$index}.values.{$valueIndex}.image_id");
                }
            }
        }
    }

    /**
     * BR-P03: no code sent belongs to a SKU other than the one it stands for (a SKU left out keeps its code).
     *
     * @param  array<int, Sku|null>  $matches
     */
    private function codes(ProductData $data, array $matches): void
    {
        $own = array_values(array_filter(array_map(fn (?Sku $sku): ?int => $sku?->id, $matches)));
        $taken = Sku::query()
            ->whereIn('code', array_map(fn (SkuData $sku): string => $sku->code, $data->skus))
            ->whereNotIn('id', $own)
            ->pluck('code')
            ->all();

        foreach ($data->skus as $sku) {
            if (in_array($sku->code, $taken, true)) {
                throw CatalogConflict::duplicateSkuCode($sku->path === 'sku' ? null : "{$sku->path}.code");
            }
        }
    }

    private function combination(Sku $sku): string
    {
        return implode('|', $sku->optionValues->sortBy([['option.sort_order', 'asc'], ['option.id', 'asc']])->map(fn (ProductOptionValue $value): string => ArabicText::normalize($value->value))->all());
    }

    /**
     * @param  list<string>  $values
     */
    private function folded(array $values): string
    {
        return implode('|', array_map(ArabicText::normalize(...), $values));
    }
}
