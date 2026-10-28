<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Product;

/** Everything the dashboard product page shows: category, SKU with units, tiers and stock, gallery, relations both ways. */
final readonly class ProductDetailQuery
{
    public function load(Product $product): Product
    {
        return $product->load([
            'category',
            'sku.activeUnits.unit',
            'sku.activeUnits.tiers',
            'sku.baseUnit',
            'sku.inventory',
            'images',
            'mainImage',
            'relations.relatedProduct',
            'incomingRelations.product',
        ]);
    }
}
