<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\ListedPrice;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-P13 compact price-list row: name, section kind, code, unit, MOQ, reference and wholesale price, availability. One row
 * per unit of every active SKU, SKUs in the merchant's order (V2 Task 14, BR-P14: `sku_id`, `variant` label), each SKU's
 * default unit first (V2 Task 13, D91; `sku_unit_id`, `conversion_factor`, `is_default`).
 * Prices follow the same visibility rule as the product list (D1) and are the shopper's own (BR-PR14): the wholesale price
 * is their negotiated or group price when lower, with `price_source` (`group` or `customer`) for the badge, or a live
 * promotion's price with `promotion { id, name, discount_percent, ends_at }` (BR-PR15, V2 Task 12).
 *
 * @property-read Product $resource
 */
final class PriceListRowResource extends JsonResource
{
    public function __construct(Product $resource, private readonly ?SkuUnit $unit, private readonly bool $pricesHidden, private readonly ?PricingContext $pricing = null, private readonly ?Sku $sku = null)
    {
        parent::__construct($resource);
    }

    /**
     * @param  Collection<int, Product>  $rows
     */
    public static function list(Collection $rows, bool $pricesHidden, bool $truncated, ?PricingContext $pricing = null): AnonymousResourceCollection
    {
        $resources = $rows->flatMap(fn (Product $product): array => $product->activeSkus->isEmpty()
            ? [new self($product, null, $pricesHidden, $pricing)]
            : $product->activeSkus->flatMap(fn (Sku $sku): array => array_map(
                fn (?SkuUnit $unit): self => new self($product, $unit, $pricesHidden, $pricing, $sku),
                $sku->activeUnits->isNotEmpty() ? $sku->activeUnits->all() : [null],
            ))->all());

        return new AnonymousResourceCollection($resources, self::class)
            ->additional(['meta' => ['count' => $resources->count(), 'truncated' => $truncated, 'prices_hidden' => $pricesHidden]]);
    }

    /**
     * The SKUs of the rows, whose promotions the PricingContext must know (BR-PR15).
     *
     * @param  Collection<int, Product>  $rows
     * @return list<int>
     */
    public static function skuIds(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $product) {
            array_push($ids, ...$product->activeSkus->map(fn (Sku $sku): int => $sku->id)->all());
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sku = $this->sku;
        $unit = $this->unit;
        $category = $this->resource->relationLoaded('category') ? $this->resource->category : null;
        $listed = $unit instanceof SkuUnit && $unit->relationLoaded('tiers') ? app(PriceResolver::class)->listed($unit, $this->pricing) : null;

        return [
            'id' => $this->resource->id,
            'sku_id' => $sku?->id,
            'variant' => $sku?->name,
            'sku_unit_id' => $unit?->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'kind' => $category instanceof Category ? $category->kind->value : null,
            'code' => $sku?->code,
            'unit' => $unit?->relationLoaded('unit') === true ? $unit->unit->name : null,
            'conversion_factor' => $unit?->conversion_factor,
            'is_default' => $unit->is_default ?? true,
            'moq' => $unit?->moq,
            'cover_price' => $this->when(! $this->pricesHidden, $unit?->cover_price),
            'base_price' => $this->when(! $this->pricesHidden, $listed->basePrice ?? $unit?->base_price),
            'price_source' => $this->when(! $this->pricesHidden && $listed instanceof ListedPrice && $listed->source->isCustomerSpecific(), fn (): ?string => $listed?->source->value),
            'promotion' => $this->when(! $this->pricesHidden && $listed?->promotion !== null, fn (): ?array => $listed?->promotion?->summary()),
            'availability' => $this->resource->status === ProductStatus::ComingSoon ? 'coming_soon' : 'available',
        ];
    }
}
