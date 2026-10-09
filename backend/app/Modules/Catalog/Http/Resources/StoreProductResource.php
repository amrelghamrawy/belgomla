<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Storefront product (08-api-contract.md §1). Custom fields are shown as `{key, label, value}` with the
 * option label, only for active fields marked `show_on_storefront`. When prices are hidden (D1) the
 * SKU prices are omitted and `prices_hidden: true` is added. `coming_soon` products are listed with
 * `in_stock: false, coming_soon: true` (BR-P04). The detail page adds `gallery` and `related` (BR-P10).
 *
 * Task 1 shape: `in_stock` follows the status until Inventory (Task 3) adds `available`; `tiers` and
 * `discount_percent` arrive with Pricing (Task 2).
 *
 * @property-read Product $resource
 */
final class StoreProductResource extends JsonResource
{
    /**
     * @param  Collection<string, ProductFieldDefinition>  $fields  StorefrontFieldsQuery::get()
     */
    public function __construct(
        Product $resource,
        private readonly Collection $fields,
        private readonly bool $pricesHidden,
    ) {
        parent::__construct($resource);
    }

    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     * @param  Collection<string, ProductFieldDefinition>  $fields
     */
    public static function page(LengthAwarePaginator $products, Collection $fields, bool $pricesHidden): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection(
            $products->through(fn (Product $product): self => new self($product, $fields, $pricesHidden)),
            self::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $mainImage = $product->relationLoaded('mainImage') ? $product->mainImage : null;
        $category = $product->relationLoaded('category') ? $product->category : null;
        $sku = $product->relationLoaded('sku') ? $product->sku : null;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'status' => $product->status->value,
            'main_image_url' => $mainImage instanceof ProductImage ? $mainImage->url() : null,
            'category' => $category instanceof Category ? ['id' => $category->id, 'name' => $category->name] : null,
            'custom_fields' => $this->customFields(),
            'sku' => $sku instanceof Sku ? $this->sku($sku) : null,
            'prices_hidden' => $this->when($this->pricesHidden, true),
            'gallery' => $this->when($product->relationLoaded('images'), fn (): AnonymousResourceCollection => ProductImageResource::collection($product->images)),
            'related' => $this->when(
                $product->relationLoaded('relations') && $product->relationLoaded('incomingRelations'),
                fn (): array => $product->relations->concat($product->incomingRelations)->sortBy('id')->values()->map($this->related(...))->all(),
            ),
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function customFields(): array
    {
        $values = $this->resource->custom_fields;
        $shown = [];

        foreach ($this->fields as $key => $definition) {
            $value = array_key_exists($key, $values) ? $this->display($definition, $values[$key]) : null;

            if ($value !== null) {
                $shown[] = ['key' => $key, 'label' => $definition->label, 'value' => $value];
            }
        }

        return $shown;
    }

    private function display(ProductFieldDefinition $definition, int|float|string|bool $value): ?string
    {
        return match ($definition->type) {
            FieldType::Select => $definition->options->first(fn (ProductFieldOption $option): bool => $option->id === $value)?->label,
            FieldType::Boolean => $value === true ? 'نعم' : 'لا',
            FieldType::Number, FieldType::Text => (string) $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sku(Sku $sku): array
    {
        $unit = $sku->relationLoaded('sellingUnit') ? $sku->sellingUnit : null;
        $comingSoon = $this->resource->status === ProductStatus::ComingSoon;

        return [
            'id' => $sku->id,
            'code' => $sku->code,
            'unit' => $unit instanceof Unit ? $unit->name : null,
            'conversion_factor' => $sku->conversion_factor,
            'moq' => $sku->moq,
            'cover_price' => $this->when(! $this->pricesHidden, $sku->cover_price),
            'base_price' => $this->when(! $this->pricesHidden, $sku->base_price),
            'in_stock' => $this->resource->status === ProductStatus::Active,
            'coming_soon' => $this->when($comingSoon, true),
        ];
    }

    /**
     * @return array{type: string, direction: string, product: self}
     */
    private function related(ProductRelation $relation): array
    {
        $outgoing = $relation->product_id === $this->resource->id;

        return [
            'type' => $relation->type->value,
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'product' => new self($outgoing ? $relation->relatedProduct : $relation->product, $this->fields, $this->pricesHidden),
        ];
    }
}
