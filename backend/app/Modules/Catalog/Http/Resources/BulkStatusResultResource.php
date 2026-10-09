<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-P12 bulk result: `{ affected, skipped, batch_id }` (D29 adds `skipped`).
 *
 * @property-read array{affected: int, skipped: int, batch_id: string} $resource
 */
final class BulkStatusResultResource extends JsonResource
{
    /**
     * @return array{affected: int, skipped: int, batch_id: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'affected' => $this->resource['affected'],
            'skipped' => $this->resource['skipped'],
            'batch_id' => $this->resource['batch_id'],
        ];
    }
}
