<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\BundleAvailability;

/**
 * Everything the dashboard product page shows: category, SKU with units, tiers and stock, gallery, relations both ways,
 * and a bundle's components with their stock (BR-P15, D93).
 */
final readonly class ProductDetailQuery
{
    public function load(Product $product): Product
    {
        return $product->load([
            'category',
            'skus.activeUnits.unit',
            'skus.activeUnits.tiers',
            'skus.baseUnit',
            'skus.inventory',
            'skus.optionValues',
            ...array_map(fn (string $relation): string => "skus.{$relation}", BundleAvailability::RELATIONS),
            'options.values.image',
            'images',
            'mainImage',
            'relations.relatedProduct',
            'incomingRelations.product',
        ]);
    }
}
