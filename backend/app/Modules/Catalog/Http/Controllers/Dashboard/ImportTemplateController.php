<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Http\Requests\ExportProductsRequest;
use App\Modules\Catalog\Services\CatalogExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ImportTemplateController
{
    /** GET /api/dashboard/products/import/template — V2 Task 06: the empty file with the tenant's custom field columns */
    public function show(ExportProductsRequest $request, CatalogExporter $exporter): StreamedResponse
    {
        $format = $request->fileFormat();

        return response()->streamDownload(
            fn () => $exporter->template('php://output', $format),
            $exporter->fileName('products-template', $format),
            ['Content-Type' => $format->mimeType()],
        );
    }
}
