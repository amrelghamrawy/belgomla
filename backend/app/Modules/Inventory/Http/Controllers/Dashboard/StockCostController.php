<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\SetStockCost;
use App\Modules\Inventory\Http\Requests\SetStockCostRequest;
use App\Modules\Inventory\Http\Resources\InventoryRowResource;
use App\Modules\Inventory\Queries\InventoryRowQuery;

final readonly class StockCostController
{
    /** PUT /api/dashboard/inventory/{sku}/cost — D96 d */
    public function update(SetStockCostRequest $request, Sku $sku, SetStockCost $setCost, InventoryRowQuery $row): InventoryRowResource
    {
        $setCost->handle($sku, $request->unitCost(), $request->skuUnitId());

        return new InventoryRowResource($row->get($sku));
    }
}
