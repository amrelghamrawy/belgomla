<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Http\Requests\ReportPageRequest;
use App\Modules\Reports\Http\Resources\OutstandingCustomerResource;
use App\Modules\Reports\Queries\OutstandingReportQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class OutstandingController
{
    /** GET /api/dashboard/reports/outstanding — D5 b, D59 */
    public function show(ReportPageRequest $request, OutstandingReportQuery $outstanding): AnonymousResourceCollection
    {
        return OutstandingCustomerResource::collection($outstanding->paginate($request->perPage()));
    }
}
