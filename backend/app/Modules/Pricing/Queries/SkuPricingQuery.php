<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The SKU units of a product (V1: exactly one SKU, BR-P02; V2 Task 13, D91: sold in several units) with their tiers, unit
 * and SKU, for the dashboard pricing endpoints. The product and unit come from route binding, already scoped to the
 * tenant (BR-T04); a unit of another product is not found. A bundle's unit is priced through its bundle (BR-P15, D93: `409 is_bundle`).
 */
final readonly class SkuPricingQuery
{
    public function forUnit(Product $product, SkuUnit $unit): SkuUnit
    {
        if ($unit->sku->product_id !== $product->id || ! $unit->isActive()) {
            throw new ModelNotFoundException()->setModel(SkuUnit::class, [$unit->id]);
        }

        if ($product->isBundle()) {
            throw InvalidPricingInput::bundle();
        }

        return $unit->load(['tiers', 'unit'])->setRelation('sku', $unit->sku->setRelation('product', $product));
    }

    /**
     * @return Collection<int, SkuUnit>
     */
    public function forProduct(Product $product): Collection
    {
        $units = new Collection;

        foreach ($product->skus()->with(['activeUnits.tiers', 'activeUnits.unit'])->get() as $sku) {
            $sku->setRelation('product', $product);

            foreach ($sku->activeUnits as $unit) {
                $units->push($unit->setRelation('sku', $sku));
            }
        }

        return $units;
    }
}
