<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A catalog export queued because the selection is large (V2 Task 06, `202`): its id and the temporary signed,
 * host-relative link to poll and download it (`409 export_not_ready` until the job has written the file).
 *
 * @property-read string $resource the export's uuid
 */
final class QueuedExportResource extends JsonResource
{
    /**
     * @return array{id: string, download_url: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource,
            'download_url' => URL::temporarySignedRoute(
                'dashboard.products.export.download',
                now()->addHours(config()->integer('catalog.import_token_hours')),
                ['export' => $this->resource],
                absolute: false,
            ),
        ];
    }
}
