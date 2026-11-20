<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Actions\ConvertPreorders;
use App\Modules\Orders\Http\Requests\ConvertPreordersRequest;
use App\Modules\Orders\Http\Resources\ConvertedPreordersResource;

final readonly class ProductPreorderConversionController
{
    /** POST /api/dashboard/products/{product}/convert-preorders — BR-O14, D88: FIFO, as far as the stock goes */
    public function store(ConvertPreordersRequest $request, Product $product, ConvertPreorders $convert): ConvertedPreordersResource
    {
        return new ConvertedPreordersResource($convert->handle($product, $request->actorId()));
    }
}
