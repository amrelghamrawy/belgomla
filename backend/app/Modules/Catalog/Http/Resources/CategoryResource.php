<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recursive tree node. Store: `{ id, name, slug, kind, icon, image_url, children }` (08-api-contract.md §1);
 * the dashboard adds parent_id, sort_order and status.
 *
 * @property-read Category $resource
 */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dashboard = $request->is('api/dashboard/*');

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'kind' => $this->resource->kind->value,
            'icon' => $this->resource->icon?->value,
            'image_url' => $this->resource->imageUrl(),
            'parent_id' => $this->when($dashboard, $this->resource->parent_id),
            'sort_order' => $this->when($dashboard, $this->resource->sort_order),
            'status' => $this->when($dashboard, $this->resource->status->value),
            'children' => self::collection($this->resource->relationLoaded('children') ? $this->resource->children : []),
        ];
    }
}
