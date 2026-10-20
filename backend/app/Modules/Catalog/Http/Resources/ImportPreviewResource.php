<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Data\ImportPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The import preview (V2 Task 06): the token to import with, the row counts by action and the first row errors
 * (`{row, column, label, message}`), `errors_truncated` when there were more.
 *
 * @property-read ImportPreview $resource
 */
final class ImportPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource->token,
            'file_name' => $this->resource->fileName,
            'total' => $this->resource->total,
            'create' => $this->resource->create,
            'update' => $this->resource->update,
            'skip' => $this->resource->skip,
            'errors' => $this->resource->errors,
            'errors_truncated' => $this->resource->errorsTruncated,
        ];
    }
}
