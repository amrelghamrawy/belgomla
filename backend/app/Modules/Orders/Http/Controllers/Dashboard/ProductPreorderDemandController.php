<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Http\Resources\PreorderDemandResource;
use App\Modules\Orders\Queries\PreorderDemandQuery;

final readonly class ProductPreorderDemandController
{
    /** GET /api/dashboard/products/{product}/preorder-demand — BR-O14: what is pre-ordered, for purchase planning */
    public function show(Product $product, PreorderDemandQuery $demand): PreorderDemandResource
    {
        return new PreorderDemandResource($product, $demand->of($product));
    }
}
