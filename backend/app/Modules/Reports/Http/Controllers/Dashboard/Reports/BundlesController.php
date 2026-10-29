<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\BundleReportData;
use App\Modules\Reports\Http\Requests\BundleReportRequest;
use App\Modules\Reports\Http\Resources\BundleReportResource;
use App\Modules\Reports\Queries\BundleSalesQuery;

final readonly class BundlesController
{
    /** GET /api/dashboard/reports/bundles?from=&to= — BR-P15, D93 */
    public function show(BundleReportRequest $request, BundleSalesQuery $bundles): BundleReportResource
    {
        $report = BundleReportData::fromRequest($request);

        return new BundleReportResource($bundles->get($report), $report);
    }
}
