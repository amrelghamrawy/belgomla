<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Dashboard;

use App\Modules\Inventory\Actions\BulkAdjustStock;
use App\Modules\Inventory\Data\BulkStockAdjustmentData;
use App\Modules\Inventory\Http\Requests\BulkAdjustStockRequest;
use App\Modules\Inventory\Http\Resources\BulkAdjustResultResource;

final readonly class BulkStockAdjustmentController
{
    /** POST /api/dashboard/inventory/bulk-adjust — BR-INV08 bulk */
    public function store(BulkAdjustStockRequest $request, BulkAdjustStock $bulk): BulkAdjustResultResource
    {
        return new BulkAdjustResultResource($bulk->handle(BulkStockAdjustmentData::fromRequest($request), $request->actorId()));
    }
}
