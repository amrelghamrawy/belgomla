<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Http\Resources\CollectionsReportResource;
use App\Modules\Reports\Queries\CollectionsReportQuery;

final readonly class CollectionsReportController
{
    /** GET /api/dashboard/reports/collections?from=&to=&group=&customer_group_id= — V2 Task 16 */
    public function show(ReportFiltersRequest $request, CollectionsReportQuery $collections): CollectionsReportResource
    {
        return new CollectionsReportResource($collections->get(ReportFiltersData::fromRequest($request)));
    }
}
