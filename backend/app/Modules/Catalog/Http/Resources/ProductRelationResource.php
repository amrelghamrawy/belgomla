<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A relation seen from one product (BR-P10): `outgoing` when this product declared it, `incoming`
 * for the automatic reverse display. `product` is always the other side.
 *
 * @property-read ProductRelation $resource
 */
final class ProductRelationResource extends JsonResource
{
    public function __construct(ProductRelation $resource, private readonly int $viewerId)
    {
        parent::__construct($resource);
    }

    /**
     * Both directions of a product whose `relations.relatedProduct` and `incomingRelations.product` are loaded.
     */
    public static function forProduct(Product $product): AnonymousResourceCollection
    {
        $relations = $product->relations->concat($product->incomingRelations)
            ->map(fn (ProductRelation $relation): self => new self($relation, $product->id));

        return new AnonymousResourceCollection($relations, self::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $outgoing = $this->resource->product_id === $this->viewerId;
        $other = $outgoing ? $this->resource->relatedProduct : $this->resource->product;

        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type->value,
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'product' => [
                'id' => $other->id,
                'name' => $other->name,
                'slug' => $other->slug,
                'status' => $other->status->value,
            ],
        ];
    }
}
