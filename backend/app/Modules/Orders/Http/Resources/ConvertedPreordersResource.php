<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Data\ConvertedPreorders;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The merchant's summary of a conversion (BR-O14, D88): `converted_orders` (each new order's number, the pre-order it
 * came from, the selling units and its total), how many pre-orders were converted only `partially` and how many were
 * left `untouched`.
 *
 * @property-read ConvertedPreorders $resource
 */
final class ConvertedPreordersResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'converted_orders' => $this->resource->orders,
            'partially' => $this->resource->partially,
            'untouched' => $this->resource->untouched,
        ];
    }
}
