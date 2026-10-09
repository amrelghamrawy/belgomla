<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard view of a custom field (BR-P08), options included.
 *
 * @property-read ProductFieldDefinition $resource
 */
final class FieldDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'type' => $this->resource->type->value,
            'is_required' => $this->resource->is_required,
            'show_on_storefront' => $this->resource->show_on_storefront,
            'is_filterable' => $this->resource->is_filterable,
            'filter_order' => $this->resource->filter_order,
            'sort_order' => $this->resource->sort_order,
            'status' => $this->resource->status->value,
            'options' => FieldOptionResource::collection($this->resource->relationLoaded('options') ? $this->resource->options : []),
        ];
    }
}
