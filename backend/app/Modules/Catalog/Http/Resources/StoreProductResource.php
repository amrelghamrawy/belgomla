<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Contracts\PreorderDemand;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\ListedPrice;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Storefront product (08-api-contract.md §1). Custom fields are shown as `{key, label, value}` with the
 * option label, only for active fields marked `show_on_storefront` that apply to the category's section (D69). When prices are hidden (D1) the
 * SKU prices are omitted and `prices_hidden: true` is added. `coming_soon` products are listed with
 * `in_stock: false, coming_soon: true` and no `available` (BR-P04). The detail page adds `gallery` and `related` (BR-P10).
 * `preorder` is null unless the product is Coming Soon; then `{ enabled, expected_at, remaining }`, `remaining` being what
 * is left under the merchant's cap in the default unit (null = no cap; pre-orders in other units count by their base units, D91), from Orders through PreorderDemand (BR-O14, D88).
 *
 * `base_price`, `discount_percent` (off the cover price) and `tiers` come from Pricing's PriceResolver, read-only
 * (BR-PR03, BR-PR10), at the shopper's own prices (BR-PR14, V2 Task 11): a negotiated price as `base_price` with no tiers,
 * a group price with only the tiers below it, and then `price_source` (`group` or `customer`) for the «سعرك الخاص» badge;
 * guests and customers without either see the SKU as stored and no `price_source`. A live promotion (BR-PR15, V2 Task 12)
 * that sets the price adds `promotion { id, name, discount_percent, ends_at }` for the offer badge, for guests too. `available` is the SKU's stock in base units from Inventory (BR-INV02, BR-U03; no
 * stock row = 0); `in_stock` means at least one selling unit can be ordered (D37).
 *
 * V2 Task 13 (D91): `sku.units` lists the units the SKU is sold in, the default first, each `{ id, unit, conversion_factor,
 * moq, prices…, is_default, in_stock }` priced on its own; the flat `unit`, `conversion_factor`, `moq` and prices of `sku`
 * are its default unit's, and `sku.in_stock` is true when any unit can be ordered.
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
        private readonly ?PricingContext $pricing = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     * @param  Collection<string, ProductFieldDefinition>  $fields
     */
    public static function page(LengthAwarePaginator $products, Collection $fields, bool $pricesHidden, ?PricingContext $pricing = null): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection(
            $products->through(fn (Product $product): self => new self($product, $fields, $pricesHidden, $pricing)),
            self::class,
        );
    }

    /**
     * The SKUs these products show, related products included when loaded: what the PricingContext must know the
     * promotions of (BR-PR15).
     *
     * @param  iterable<Product>  $products
     * @return list<int>
     */
    public static function skuIds(iterable $products): array
    {
        $ids = [];

        foreach ($products as $product) {
            $related = [
                ...($product->relationLoaded('relations') ? $product->relations->map(fn (ProductRelation $relation): Product => $relation->relatedProduct)->all() : []),
                ...($product->relationLoaded('incomingRelations') ? $product->incomingRelations->map(fn (ProductRelation $relation): Product => $relation->product)->all() : []),
            ];

            foreach ([$product, ...$related] as $shown) {
                if ($shown->relationLoaded('sku') && $shown->sku instanceof Sku) {
                    $ids[] = $shown->sku->id;
                }
            }
        }

        return array_values(array_unique($ids));
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
            'category' => $category instanceof Category ? ['id' => $category->id, 'name' => $category->name, 'kind' => $category->kind->value] : null,
            'custom_fields' => $this->customFields(),
            'sku' => $sku instanceof Sku ? $this->sku($sku) : null,
            'preorder' => $this->preorder(),
            'prices_hidden' => $this->when($this->pricesHidden, true),
            'gallery' => $this->when($product->relationLoaded('images'), fn (): AnonymousResourceCollection => ProductImageResource::collection($product->images)),
            'related' => $this->when(
                $product->relationLoaded('relations') && $product->relationLoaded('incomingRelations'),
                fn (): array => $product->relations->concat($product->incomingRelations)->sortBy('id')->values()->map($this->related(...))->all(),
            ),
        ];
    }

    /**
     * @return array{enabled: bool, expected_at: string|null, remaining: int|null}|null
     */
    private function preorder(): ?array
    {
        $product = $this->resource;

        if ($product->status !== ProductStatus::ComingSoon) {
            return null;
        }

        $factor = $this->defaultFactor();
        $remaining = $product->preorder_enabled && $product->preorder_limit !== null
            ? intdiv(max(0, $product->preorder_limit * $factor - app(PreorderDemand::class)->of($product)['quantity_base']), $factor)
            : null;

        return ['enabled' => $product->preorder_enabled, 'expected_at' => $product->expected_at?->toDateString(), 'remaining' => $remaining];
    }

    /** D91: base units in one of the SKU's default unit, the unit the pre-order cap is in. */
    private function defaultFactor(): int
    {
        $sku = $this->resource->relationLoaded('sku') ? $this->resource->sku : null;
        $units = $sku instanceof Sku && $sku->relationLoaded('activeUnits') ? $sku->activeUnits : new Collection;

        return max(1, $units->firstWhere('is_default', true)->conversion_factor ?? 1);
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function customFields(): array
    {
        $values = $this->resource->custom_fields;
        $category = $this->resource->relationLoaded('category') ? $this->resource->category : null;
        $shown = [];

        foreach ($this->fields as $key => $definition) {
            if ($category instanceof Category && ! $definition->appliesTo($category->kind)) {
                continue;
            }

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
     * The SKU, priced as its default unit (what cards and lists show), and every active unit in `units` (D91).
     *
     * @return array<string, mixed>
     */
    private function sku(Sku $sku): array
    {
        $units = $sku->relationLoaded('activeUnits') ? $sku->activeUnits : new Collection;
        $default = $units->firstWhere('is_default', true) ?? $units->first();
        $comingSoon = $this->resource->status === ProductStatus::ComingSoon;
        $available = $sku->relationLoaded('inventory') ? $sku->inventory->available ?? 0 : 0;
        $smallest = $units->min('conversion_factor');

        return [
            'id' => $sku->id,
            'code' => $sku->code,
            ...($default instanceof SkuUnit ? $this->unitPrices($default) : []),
            'units' => $units->map(fn (SkuUnit $unit): array => [
                'id' => $unit->id,
                ...$this->unitPrices($unit),
                'is_default' => $unit->id === $default?->id,
                'in_stock' => $this->orderable($available, $unit->conversion_factor),
            ])->values()->all(),
            'available' => $this->when(! $comingSoon, $available),
            'in_stock' => is_int($smallest) && $this->orderable($available, $smallest),
            'coming_soon' => $this->when($comingSoon, true),
        ];
    }

    /**
     * One unit's name, factor, MOQ and prices at the shopper's own prices (BR-PR14, BR-PR15).
     *
     * @return array<string, mixed>
     */
    private function unitPrices(SkuUnit $unit): array
    {
        $listed = $unit->relationLoaded('tiers') ? app(PriceResolver::class)->listed($unit, $this->pricing) : null;

        return [
            'unit' => $unit->relationLoaded('unit') ? $unit->unit->name : null,
            'conversion_factor' => $unit->conversion_factor,
            'moq' => $unit->moq,
            'cover_price' => $this->when(! $this->pricesHidden, $unit->cover_price),
            'base_price' => $this->when(! $this->pricesHidden, $listed->basePrice ?? $unit->base_price),
            'discount_percent' => $this->when(! $this->pricesHidden, fn (): ?string => $listed instanceof ListedPrice ? $listed->discountPercent : app(PriceResolver::class)->baseDiscountPercent($unit)),
            'tiers' => $this->when(! $this->pricesHidden && $listed instanceof ListedPrice, fn (): array => $this->tiers($listed->tiers ?? [])),
            'price_source' => $this->when(! $this->pricesHidden && $listed instanceof ListedPrice && $listed->source->isCustomerSpecific(), fn (): ?string => $listed?->source->value),
            'promotion' => $this->when(! $this->pricesHidden && $listed?->promotion !== null, fn (): ?array => $listed?->promotion?->summary()),
        ];
    }

    /** D37: the product is active and at least one of the unit can be ordered. */
    private function orderable(int $available, int $factor): bool
    {
        return $this->resource->status === ProductStatus::Active && $available >= $factor;
    }

    /**
     * @param  list<TierData>  $tiers
     * @return list<array{min_quantity: int, unit_price: string|null}>
     */
    private function tiers(array $tiers): array
    {
        return array_map(fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'unit_price' => $tier->unitPrice], $tiers);
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
            'product' => new self($outgoing ? $relation->relatedProduct : $relation->product, $this->fields, $this->pricesHidden, $this->pricing),
        ];
    }
}
