<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Http\Requests\ListInventoryRequest;
use App\Modules\Inventory\Http\Resources\InventoryRowResource;
use App\Modules\Inventory\Queries\InventoryListQuery;
use App\Modules\Inventory\Queries\InventoryRowQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class InventoryController
{
    /** GET /api/dashboard/inventory?q=&low_stock=1&sort=&page=&per_page= */
    public function index(ListInventoryRequest $request, InventoryListQuery $inventory): AnonymousResourceCollection
    {
        return InventoryRowResource::collection(
            $inventory->paginate($request->q(), $request->lowStock(), $request->sort(), $request->perPage()),
        );
    }

    /** GET /api/dashboard/inventory/{sku} — the adjust form shows `min_allowed` from here (BR-INV08). */
    public function show(Sku $sku, InventoryRowQuery $row): InventoryRowResource
    {
        return new InventoryRowResource($row->get($sku));
    }
}
