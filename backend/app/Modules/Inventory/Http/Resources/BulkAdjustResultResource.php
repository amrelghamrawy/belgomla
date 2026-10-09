<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bulk adjust result (08-api-contract.md §7): `{ applied, skipped: [{ sku_id, reason, reserved?, min_allowed? }], batch_id }`.
 *
 * @property-read array{applied: int, skipped: list<array<string, int|string>>, batch_id: string} $resource
 */
final class BulkAdjustResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'applied' => $this->resource['applied'],
            'skipped' => $this->resource['skipped'],
            'batch_id' => $this->resource['batch_id'],
        ];
    }
}
