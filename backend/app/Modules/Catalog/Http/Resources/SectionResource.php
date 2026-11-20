<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store section as categories, products and fields name it (D109): what it is called, what its reference price is
 * called (BR-PR10) and its icon (D73).
 *
 * @property-read Section $resource
 */
final class SectionResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, reference_price_label: string, icon: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'reference_price_label' => $this->resource->reference_price_label,
            'icon' => $this->resource->icon?->value,
        ];
    }
}
