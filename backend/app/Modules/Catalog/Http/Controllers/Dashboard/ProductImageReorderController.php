<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\ReorderProductImages;
use App\Modules\Catalog\Http\Requests\ReorderProductImagesRequest;
use App\Modules\Catalog\Http\Resources\ProductImageResource;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ProductImageReorderController
{
    /** POST /api/dashboard/products/{product}/images/reorder — `{ ids: [...] }` */
    public function store(ReorderProductImagesRequest $request, Product $product, ReorderProductImages $reorder): AnonymousResourceCollection
    {
        return ProductImageResource::collection($reorder->handle($product, $request->ids()));
    }
}
