<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Http\Resources\InventoryAnalyticsResource;
use App\Modules\Reports\Queries\InventoryAnalyticsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class InventoryAnalyticsController
{
    /** GET /api/dashboard/reports/inventory?from=&to=&category_id=&dead_days=&dead_only= — V2 Task 16, D94 */
    public function show(ReportFiltersRequest $request, InventoryAnalyticsQuery $inventory): AnonymousResourceCollection
    {
        $filters = ReportFiltersData::fromRequest($request);
        $report = $inventory->paginate($filters, $request->deadDays(), $request->deadOnly(), $request->page(), $request->perPage());

        return InventoryAnalyticsResource::collection($report['page'])->additional([
            'from' => $filters->from,
            'to' => $filters->to,
            'dead_days' => $request->deadDays(),
            'summary' => $inventory->summary($filters, $request->deadDays(), $request->deadOnly())['data'],
            'generated_at' => $report['generated_at'],
        ]);
    }
}
