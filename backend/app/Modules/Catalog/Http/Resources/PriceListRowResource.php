<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-P13 compact price-list row: name, code, unit, MOQ, cover and wholesale price, availability.
 * Prices follow the same visibility rule as the product list (D1).
 *
 * @property-read Product $resource
 */
final class PriceListRowResource extends JsonResource
{
    public function __construct(Product $resource, private readonly bool $pricesHidden)
    {
        parent::__construct($resource);
    }

    /**
     * @param  Collection<int, Product>  $rows
     */
    public static function list(Collection $rows, bool $pricesHidden, bool $truncated): AnonymousResourceCollection
    {
        $resources = $rows->map(fn (Product $product): self => new self($product, $pricesHidden));

        return new AnonymousResourceCollection($resources, self::class)
            ->additional(['meta' => ['count' => $rows->count(), 'truncated' => $truncated, 'prices_hidden' => $pricesHidden]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sku = $this->resource->relationLoaded('sku') ? $this->resource->sku : null;
        $unit = $sku instanceof Sku && $sku->relationLoaded('sellingUnit') ? $sku->sellingUnit : null;

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'code' => $sku?->code,
            'unit' => $unit instanceof Unit ? $unit->name : null,
            'moq' => $sku?->moq,
            'cover_price' => $this->when(! $this->pricesHidden, $sku?->cover_price),
            'base_price' => $this->when(! $this->pricesHidden, $sku?->base_price),
            'availability' => $this->resource->status === ProductStatus::ComingSoon ? 'coming_soon' : 'available',
        ];
    }
}
