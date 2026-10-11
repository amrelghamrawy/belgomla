<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Checks the ids a product payload points at, within the tenant in context (BR-T04): the category
 * (D25: required), both units, and the SKU code (BR-P03: unique per tenant). Archived categories and
 * units cannot be newly chosen, but a product may keep the ones it already has.
 */
final readonly class ProductReferences
{
    /** Returns the (new) category so callers can read its kind (D69). */
    public function check(ProductData $data, ?Product $existing = null): Category
    {
        $sku = $existing?->sku;

        $category = $this->category($data->categoryId, $existing?->category_id);
        $this->unit('sku.selling_unit_id', $data->sku->sellingUnitId, $sku?->selling_unit_id);
        $this->unit('sku.base_unit_id', $data->sku->baseUnitId, $sku?->base_unit_id);

        $taken = Sku::query()
            ->where('code', $data->sku->code)
            ->when($sku instanceof Sku, fn (Builder $query): Builder => $query->whereKeyNot($sku?->id))
            ->exists();

        if ($taken) {
            throw CatalogConflict::duplicateSkuCode();
        }

        return $category;
    }

    private function category(int $id, ?int $current): Category
    {
        $category = Category::query()->find($id) ?? throw InvalidCatalogInput::unknownReference('category_id');

        if ($category->status === ArchiveStatus::Archived && $id !== $current) {
            throw InvalidCatalogInput::archivedReference('category_id');
        }

        return $category;
    }

    private function unit(string $field, int $id, ?int $current): void
    {
        $unit = Unit::query()->find($id) ?? throw InvalidCatalogInput::unknownReference($field);

        if ($unit->status === ArchiveStatus::Archived && $id !== $current) {
            throw InvalidCatalogInput::archivedReference($field);
        }
    }
}
