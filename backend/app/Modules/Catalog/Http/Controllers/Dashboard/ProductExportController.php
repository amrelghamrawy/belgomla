<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Http\Requests\DownloadExportRequest;
use App\Modules\Catalog\Http\Requests\ExportProductsRequest;
use App\Modules\Catalog\Http\Resources\QueuedExportResource;
use App\Modules\Catalog\Services\CatalogExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ProductExportController
{
    /** GET /api/dashboard/products/export — V2 Task 06: the filtered catalog as a file, or `202` with a link when large */
    public function index(ExportProductsRequest $request, CatalogExporter $exporter): StreamedResponse|JsonResponse
    {
        $filter = ProductFilterData::fromRequest($request);
        $format = $request->fileFormat();
        $queued = $exporter->queueIfLarge($filter, $format);

        if ($queued !== null) {
            return new QueuedExportResource($queued)->response()->setStatusCode(202);
        }

        return response()->streamDownload(
            fn () => $exporter->write('php://output', $filter, $format),
            $exporter->fileName('products', $format),
            ['Content-Type' => $format->mimeType()],
        );
    }

    /** GET /api/dashboard/products/export/{export} — the signed link of a queued export */
    public function show(DownloadExportRequest $request, string $export, CatalogExporter $exporter): BinaryFileResponse
    {
        $file = $exporter->download($export, $request->validSignature());

        return response()->download($file['path'], $exporter->fileName('products', $file['format']), ['Content-Type' => $file['format']->mimeType()]);
    }
}
