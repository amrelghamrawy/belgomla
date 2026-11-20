<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Http\Resources\CustomerPatternResource;
use App\Modules\Reports\Queries\CustomerPatternsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CustomerPatternsController
{
    /** GET /api/dashboard/reports/customers?from=&to=&customer_group_id=&view=top|dormant&dormant_days= — V2 Task 16 */
    public function show(ReportFiltersRequest $request, CustomerPatternsQuery $customers): AnonymousResourceCollection
    {
        $filters = ReportFiltersData::fromRequest($request);
        $report = $customers->paginate($filters, $request->customerView(), $request->dormantDays(), $request->page(), $request->perPage());

        return CustomerPatternResource::collection($report['page'])->additional([
            'from' => $filters->from,
            'to' => $filters->to,
            'view' => $request->customerView()->value,
            'dormant_days' => $request->dormantDays(),
            'generated_at' => $report['generated_at'],
        ]);
    }
}
