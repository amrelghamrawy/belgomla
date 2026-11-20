<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Http\Resources\ProfitAndLossResource;
use App\Modules\Reports\Queries\ProfitAndLossQuery;

final readonly class ProfitAndLossController
{
    /** GET /api/dashboard/reports/profit-and-loss?from=&to= — V2 Task 19, D100 */
    public function show(ReportFiltersRequest $request, ProfitAndLossQuery $report): ProfitAndLossResource
    {
        return new ProfitAndLossResource($report->get(ReportFiltersData::fromRequest($request)));
    }
}
