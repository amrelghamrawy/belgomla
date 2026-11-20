<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Dashboard\Reports;

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Data\ReportOptionsData;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Services\ReportExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ReportExportController
{
    /** GET /api/dashboard/reports/{report}/export?format=xlsx|csv&<the report's filters> — V2 Task 16, D83 */
    public function show(ReportFiltersRequest $request, ReportName $report, ReportExporter $exporter): StreamedResponse
    {
        $filters = ReportFiltersData::fromRequest($request);
        $options = ReportOptionsData::fromRequest($request);
        $format = $request->fileFormat();

        return response()->streamDownload(
            fn () => $exporter->write('php://output', $report, $filters, $options, $format),
            $exporter->fileName($report, $filters, $format),
            ['Content-Type' => $format->mimeType()],
        );
    }
}
