<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Dashboard;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Http\Requests\ListMovementsRequest;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Modules\Inventory\Queries\MovementsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MovementsController
{
    /** GET /api/dashboard/inventory/{sku}/movements?page=&per_page= — BR-INV06 */
    public function index(ListMovementsRequest $request, Sku $sku, MovementsQuery $movements): AnonymousResourceCollection
    {
        return MovementResource::collection($movements->paginate($sku, $request->perPage()));
    }
}
