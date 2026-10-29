<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-P15 (D93): makes a bundle's components what the form sent, in that order (`sort_order`). The components are
 * replaced as a whole: orders keep their own snapshot lines, so nothing points at a bundle item. The component units were
 * checked by BundleReferences. Joins the caller's transaction (CreateBundle, UpdateBundle).
 */
final readonly class SaveBundleItems
{
    /**
     * @param  non-empty-list<BundleComponentData>  $components
     * @param  Collection<int, SkuUnit>  $units  the component units by id
     * @return Collection<int, BundleItem> the items, their component unit set
     */
    public function handle(Sku $bundle, array $components, Collection $units): Collection
    {
        BundleItem::query()->where('bundle_sku_id', $bundle->id)->delete();

        $items = new Collection;

        foreach ($components as $index => $component) {
            $item = BundleItem::query()->create([
                'bundle_sku_id' => $bundle->id,
                'component_sku_unit_id' => $component->skuUnitId,
                'quantity' => $component->quantity,
                'sort_order' => $index,
            ]);

            $unit = $units->get($component->skuUnitId);

            if ($unit instanceof SkuUnit) {
                $item->setRelation('componentUnit', $unit);
            }

            $items->push($item);
        }

        return $items;
    }
}
