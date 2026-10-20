<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Dashboard;

use App\Modules\Catalog\Actions\RunCatalogImport;
use App\Modules\Catalog\Http\Requests\RunImportRequest;
use App\Modules\Catalog\Http\Resources\CatalogImportResource;
use App\Modules\Catalog\Models\CatalogImport;
use Illuminate\Http\JsonResponse;

final readonly class ImportController
{
    /** POST /api/dashboard/products/import — V2 Task 06: queue the import of a previewed file */
    public function store(RunImportRequest $request, RunCatalogImport $run): JsonResponse
    {
        return new CatalogImportResource($run->handle($request->token(), $request->actorId()))->response()->setStatusCode(202);
    }

    /** GET /api/dashboard/imports/{import} — progress and errors of an import */
    public function show(CatalogImport $import): CatalogImportResource
    {
        return new CatalogImportResource($import);
    }
}
