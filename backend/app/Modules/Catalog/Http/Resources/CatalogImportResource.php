<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\CatalogImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One import run and its progress (V2 Task 06): status, rows counted / processed, what they did, the stored row
 * errors (row 0 = the whole file) and the audit batch that holds every change.
 *
 * @property-read CatalogImport $resource
 */
final class CatalogImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $import = $this->resource;

        return [
            'id' => $import->id,
            'file_name' => $import->file_name,
            'status' => $import->status->value,
            'total' => $import->total,
            'processed' => $import->processed,
            'created' => $import->created,
            'updated' => $import->updated,
            'skipped' => $import->skipped,
            'failed' => $import->failed,
            'errors' => $import->errors,
            'batch_id' => $import->batch_id,
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at->toIso8601String(),
        ];
    }
}
