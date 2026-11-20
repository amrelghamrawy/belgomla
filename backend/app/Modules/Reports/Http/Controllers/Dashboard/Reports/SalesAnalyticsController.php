<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Http\Resources\SalesAnalyticsResource;
use App\Modules\Reports\Queries\SalesAnalyticsQuery;

final readonly class SalesAnalyticsController
{
    /** GET /api/dashboard/reports/sales-analytics?from=&to=&group=&category_id=&customer_group_id= — V2 Task 16 */
    public function show(ReportFiltersRequest $request, SalesAnalyticsQuery $sales): SalesAnalyticsResource
    {
        return new SalesAnalyticsResource($sales->get(ReportFiltersData::fromRequest($request)));
    }
}
