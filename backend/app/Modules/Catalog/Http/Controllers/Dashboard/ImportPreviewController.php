<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\PreviewCatalogImport;
use App\Modules\Catalog\Http\Requests\PreviewImportRequest;
use App\Modules\Catalog\Http\Resources\ImportPreviewResource;

final readonly class ImportPreviewController
{
    /** POST /api/dashboard/products/import/preview — V2 Task 06: validate a file, write nothing */
    public function store(PreviewImportRequest $request, PreviewCatalogImport $preview): ImportPreviewResource
    {
        return new ImportPreviewResource($preview->handle($request->upload()));
    }
}
