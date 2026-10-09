<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Product;

/** Both directions of a product's relations with the product on the other side (BR-P10). */
final readonly class ProductRelationsQuery
{
    public function load(Product $product): Product
    {
        return $product->load(['relations.relatedProduct', 'incomingRelations.product']);
    }
}
