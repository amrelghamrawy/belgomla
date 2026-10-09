<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\BulkSetProductStatus;
use App\Modules\Catalog\Data\BulkStatusData;
use App\Modules\Catalog\Http\Requests\BulkProductStatusRequest;
use App\Modules\Catalog\Http\Resources\BulkStatusResultResource;

final readonly class ProductBulkStatusController
{
    /** POST /api/dashboard/products/bulk/status — BR-P12 */
    public function store(BulkProductStatusRequest $request, BulkSetProductStatus $bulk): BulkStatusResultResource
    {
        return new BulkStatusResultResource($bulk->handle(BulkStatusData::fromRequest($request)));
    }
}
