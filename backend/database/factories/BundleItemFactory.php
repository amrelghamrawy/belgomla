<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\BundleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One component of a bundle (D93): `quantity` of a SKU unit (a new SKU's default unit unless given) in a bundle SKU.
 *
 * @extends Factory<BundleItem>
 */
final class BundleItemFactory extends Factory
{
    protected $model = BundleItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bundle_sku_id' => SkuFactory::new()->state(['product_id' => ProductFactory::new()->bundle()]),
            'component_sku_unit_id' => fn (): int => SkuFactory::defaultUnitIdOf(SkuFactory::new()->createOne()->id),
            'quantity' => 1,
            'sort_order' => 0,
        ];
    }
}
