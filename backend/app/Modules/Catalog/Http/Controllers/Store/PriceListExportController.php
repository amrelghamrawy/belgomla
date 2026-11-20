<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Http\Requests\ExportPriceListRequest;
use App\Modules\Catalog\Services\PriceListExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class PriceListExportController
{
    /** GET /api/store/price-list/export — BR-P13, V2 Task 06: the price list as an Excel file */
    public function index(ExportPriceListRequest $request, PriceListExporter $exporter): StreamedResponse
    {
        return response()->streamDownload(
            $exporter->writer(ProductFilterData::fromRequest($request), $request->customer()),
            'price-list-'.now()->format('Y-m-d').'.xlsx',
            ['Content-Type' => SpreadsheetFormat::Xlsx->mimeType()],
        );
    }
}
