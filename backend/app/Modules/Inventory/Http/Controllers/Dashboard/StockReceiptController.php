<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Data\StockReceiptData;
use App\Modules\Inventory\Http\Requests\ReceiveStockRequest;
use App\Modules\Inventory\Http\Resources\InventoryRowResource;
use App\Modules\Inventory\Queries\InventoryRowQuery;

final readonly class StockReceiptController
{
    /** POST /api/dashboard/inventory/{sku}/receive — BR-INV09, D96 b */
    public function store(ReceiveStockRequest $request, Sku $sku, ReceiveStock $receive, InventoryRowQuery $row): InventoryRowResource
    {
        $receive->handle($sku, StockReceiptData::fromRequest($request), $request->actorId());

        return new InventoryRowResource($row->get($sku));
    }
}
