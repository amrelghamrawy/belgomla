<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Http\Requests\OverviewReportRequest;
use App\Modules\Reports\Http\Resources\OverviewResource;
use App\Modules\Reports\Queries\OverviewQuery;

final readonly class OverviewController
{
    /** GET /api/dashboard/reports/overview — D5 */
    public function show(OverviewReportRequest $request, OverviewQuery $overview): OverviewResource
    {
        return new OverviewResource($overview->get($request->timezone()));
    }
}
