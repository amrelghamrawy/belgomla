<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Http\Resources\InventoryRowResource;
use App\Modules\Inventory\Queries\InventoryRowQuery;

final readonly class StockAdjustmentController
{
    /** POST /api/dashboard/inventory/{sku}/adjust — BR-INV06, BR-INV08 */
    public function store(AdjustStockRequest $request, Sku $sku, AdjustStock $adjust, InventoryRowQuery $row): InventoryRowResource
    {
        $adjust->handle($sku, StockAdjustmentData::fromRequest($request), $request->actorId());

        return new InventoryRowResource($row->get($sku));
    }
}
