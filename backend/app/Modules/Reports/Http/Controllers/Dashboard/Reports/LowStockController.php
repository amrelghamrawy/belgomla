<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Http\Requests\ReportPageRequest;
use App\Modules\Reports\Http\Resources\LowStockSkuResource;
use App\Modules\Reports\Queries\LowStockReportQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class LowStockController
{
    /** GET /api/dashboard/reports/low-stock — D5 c, D36 */
    public function show(ReportPageRequest $request, LowStockReportQuery $lowStock): AnonymousResourceCollection
    {
        return LowStockSkuResource::collection($lowStock->paginate($request->perPage()));
    }
}
