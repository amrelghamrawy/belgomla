<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard view of a product with its SKU (08-api-contract.md §6). The detail endpoint also loads
 * `images` and `relations`; the list leaves them out. `custom_fields` is the raw stored map
 * (select values are option ids) because the dashboard form edits it. Tiers (Task 2) and inventory
 * (Task 3) are added by those tasks.
 *
 * @property-read Product $resource
 */
final class DashboardProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'status' => $product->status->value,
            'category' => $this->category(),
            'custom_fields' => (object) $product->custom_fields,
            'sku' => $this->sku(),
            'main_image_url' => $this->mainImage()?->url(),
            'images' => $this->whenLoaded('images', fn () => ProductImageResource::collection($product->images)),
            'relations' => $this->when(
                $product->relationLoaded('relations') && $product->relationLoaded('incomingRelations'),
                fn (): AnonymousResourceCollection => ProductRelationResource::forProduct($product),
            ),
            'created_at' => $product->created_at->toIso8601String(),
            'updated_at' => $product->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function category(): ?array
    {
        $category = $this->resource->relationLoaded('category') ? $this->resource->category : null;

        return $category instanceof Category ? ['id' => $category->id, 'name' => $category->name] : null;
    }

    private function mainImage(): ?ProductImage
    {
        return $this->resource->relationLoaded('mainImage') ? $this->resource->mainImage : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sku(): ?array
    {
        $sku = $this->resource->relationLoaded('sku') ? $this->resource->sku : null;

        if (! $sku instanceof Sku) {
            return null;
        }

        return [
            'id' => $sku->id,
            'code' => $sku->code,
            'selling_unit' => $this->unit($sku, 'sellingUnit'),
            'base_unit' => $this->unit($sku, 'baseUnit'),
            'conversion_factor' => $sku->conversion_factor,
            'cover_price' => $sku->cover_price,
            'base_price' => $sku->base_price,
            'base_price_percent' => $sku->base_price_percent,
            'moq' => $sku->moq,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function unit(Sku $sku, string $relation): ?array
    {
        $unit = $sku->relationLoaded($relation) ? $sku->getRelation($relation) : null;

        return $unit instanceof Unit ? ['id' => $unit->id, 'name' => $unit->name] : null;
    }
}
