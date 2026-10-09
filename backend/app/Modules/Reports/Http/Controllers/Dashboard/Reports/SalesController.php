<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\SalesReportData;
use App\Modules\Reports\Http\Requests\SalesReportRequest;
use App\Modules\Reports\Http\Resources\SalesReportResource;
use App\Modules\Reports\Queries\SalesQuery;

final readonly class SalesController
{
    /** GET /api/dashboard/reports/sales?from=&to=&group=day|week|month — D5 a, D58 */
    public function show(SalesReportRequest $request, SalesQuery $sales): SalesReportResource
    {
        $report = SalesReportData::fromRequest($request);

        return new SalesReportResource($sales->get($report), $report);
    }
}
