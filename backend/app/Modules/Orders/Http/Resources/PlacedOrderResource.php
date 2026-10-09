<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The checkout answer, `201 { data: { order: { …order detail… } } }` (08-api-contract.md §3).
 *
 * @property-read Order $resource
 */
final class PlacedOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order' => new OrderResource($this->resource),
        ];
    }
}
