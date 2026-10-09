<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-PR11 bulk result: `{ affected, skipped_no_cover_price: [{ product_id, sku_id, code, name }] }`, plus
 * `batch_id` once applied (a preview writes nothing, so it has none).
 *
 * @property-read array{affected: int, skipped_no_cover_price: list<array{product_id: int, sku_id: int, code: string, name: string}>, batch_id: string|null} $resource
 */
final class BulkPricingResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'affected' => $this->resource['affected'],
            'skipped_no_cover_price' => $this->resource['skipped_no_cover_price'],
            'batch_id' => $this->when($this->resource['batch_id'] !== null, $this->resource['batch_id']),
        ];
    }
}
