<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Http\Resources\ProductPerformanceResource;
use App\Modules\Reports\Queries\ProductPerformanceQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ProductPerformanceController
{
    /** GET /api/dashboard/reports/products?from=&to=&category_id=&customer_group_id=&sort=revenue|quantity|return_rate — V2 Task 16 */
    public function show(ReportFiltersRequest $request, ProductPerformanceQuery $products): AnonymousResourceCollection
    {
        $filters = ReportFiltersData::fromRequest($request);
        $report = $products->paginate($filters, $request->productSort(), $request->page(), $request->perPage());

        return ProductPerformanceResource::collection($report['page'])->additional([
            'from' => $filters->from,
            'to' => $filters->to,
            'sort' => $request->productSort()->value,
            'generated_at' => $report['generated_at'],
        ]);
    }
}
