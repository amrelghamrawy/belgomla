<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Checks what a bundle payload points at, within the tenant in context (BR-T04, BR-P15, D93): the category and the unit
 * the bundle is sold in (archived ones only when the bundle already has them), the code (BR-P03, unique per tenant), and
 * every component: a SKU unit of this store that is still sold, of an active SKU of an **active simple** product (no
 * bundle inside a bundle, D93 a). A refusal names the component (`components.{i}.sku_unit_id`).
 */
final readonly class BundleReferences
{
    /**
     * @return array{0: Category, 1: Collection<int, SkuUnit>} the category and the component units by id, each with
     *                                                         its unit, tiers, SKU, product and stock loaded
     */
    public function check(BundleData $data, ?Product $existing = null): array
    {
        $sku = $existing?->skus()->first();
        $category = $this->category($data->categoryId, $existing?->category_id);
        $this->unit($data->unitId, $sku?->base_unit_id);
        $this->code($data->code, $sku);

        return [$category, $this->components($data, $existing)];
    }

    private function category(int $id, ?int $current): Category
    {
        $category = Category::query()->find($id) ?? throw InvalidCatalogInput::unknownReference('category_id');

        if ($category->status === ArchiveStatus::Archived && $id !== $current) {
            throw InvalidCatalogInput::archivedReference('category_id');
        }

        return $category;
    }

    private function unit(int $id, ?int $current): void
    {
        $unit = Unit::query()->find($id) ?? throw InvalidCatalogInput::unknownReference('unit_id');

        if ($unit->status === ArchiveStatus::Archived && $id !== $current) {
            throw InvalidCatalogInput::archivedReference('unit_id');
        }
    }

    private function code(string $code, ?Sku $own): void
    {
        $query = Sku::query()->where('code', $code);

        if ($own instanceof Sku) {
            $query->whereKeyNot($own->id);
        }

        $taken = $query->exists();

        if ($taken) {
            throw CatalogConflict::duplicateSkuCode('code');
        }
    }

    /**
     * @return Collection<int, SkuUnit>
     */
    private function components(BundleData $data, ?Product $existing): Collection
    {
        $units = SkuUnit::query()
            ->with(['unit', 'tiers', 'sku.product', 'sku.inventory'])
            ->whereIn('id', array_map(fn (BundleComponentData $component): int => $component->skuUnitId, $data->components))
            ->get()
            ->keyBy('id');

        foreach ($data->components as $index => $component) {
            $field = "components.{$index}.sku_unit_id";
            $unit = $units->get($component->skuUnitId) ?? throw InvalidCatalogInput::unknownReference($field);
            $product = $unit->sku->product;

            if ($product->isBundle() || $product->id === $existing?->id) {
                throw InvalidCatalogInput::field($field, 'لا يمكن وضع باقة داخل باقة');
            }

            if ($product->status !== ProductStatus::Active || ! $unit->sku->isActive() || ! $unit->isActive()) {
                throw InvalidCatalogInput::field($field, 'الصنف غير نشط');
            }
        }

        return $units;
    }
}
