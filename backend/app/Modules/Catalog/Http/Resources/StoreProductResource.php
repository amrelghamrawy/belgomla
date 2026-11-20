<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Contracts\PreorderDemand;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Services\BundleAvailability;
use App\Modules\Pricing\Data\ListedPrice;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\BundlePricer;
use App\Modules\Pricing\Services\PriceResolver;
use App\Support\Money;
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
 * V2 Task 14 (BR-P14): `skus` lists the active SKUs (variants), each priced like `sku` with its `label` and
 * `option_value_ids` (option order); `options` the variant options with only the values an active SKU uses (and the
 * swatch `image_url`); `sku` is the first active SKU in stock, else the first active one (cards, lists, pre-order cap);
 * `price_range { min, max }` spans the default units' prices over the active SKUs (left out when prices are hidden).
 *
 * V2 Task 15 (BR-P15, D93): `type` is `simple` or `bundle`. A bundle has no stock of its own: its SKU's `available` and
 * `in_stock` follow `availability`, the bundles its components allow now (BundleAvailability). It adds `components[]`
 * (`product_name`, `slug`, `sku_code`, `label`, `unit`, `quantity`, `in_stock` = the component allows one bundle, so an
 * unavailable bundle names what is missing) and, when prices are shown, `components_total` (the components bought on
 * their own at the shopper's prices) and `saving` (that less the bundle's price, never below 0) for «وفّر».
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
                if ($shown->relationLoaded('activeSkus')) {
                    foreach ($shown->activeSkus as $sku) {
                        $ids[] = $sku->id;

                        if ($sku->relationLoaded('bundleItems')) {
                            array_push($ids, ...$sku->bundleItems->map(fn (BundleItem $item): int => $item->componentUnit->sku_id)->all()); // D93: the components' own prices
                        }
                    }
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
        $sku = $this->primary();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'status' => $product->status->value,
            'type' => $product->type->value,
            'main_image_url' => $mainImage instanceof ProductImage ? $mainImage->url() : null,
            'category' => $category instanceof Category ? [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->section->key, // until V3 Task 03 (D109)
                'section' => new SectionResource($category->section)->toArray($request),
            ] : null,
            'custom_fields' => $this->customFields(),
            'sku' => $sku instanceof Sku ? $this->sku($sku) : null,
            'skus' => $this->skus()->map(fn (Sku $sku): array => $this->sku($sku))->values()->all(),
            'options' => $this->options(),
            'price_range' => $this->when(! $this->pricesHidden, fn (): ?array => $this->priceRange()),
            'preorder' => $this->preorder(),
            'components' => $this->when($product->isBundle(), fn (): array => $this->components()),
            'availability' => $this->when($product->isBundle(), fn (): int => $this->bundleAvailability()),
            'components_total' => $this->when($product->isBundle() && ! $this->pricesHidden, fn (): ?string => $this->componentsTotal()),
            'saving' => $this->when($product->isBundle() && ! $this->pricesHidden, fn (): ?string => $this->saving()),
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
        $sku = $this->primary();
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
            if ($category instanceof Category && ! $definition->appliesTo($category->section_id)) {
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
        $available = $this->available($sku);
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
            'label' => $sku->name,
            'option_value_ids' => $this->optionValueIds($sku),
        ];
    }

    /**
     * @return Collection<int, Sku>
     */
    private function skus(): Collection
    {
        return $this->resource->relationLoaded('activeSkus') ? $this->resource->activeSkus : new Collection;
    }

    /** The SKU standing for the product: the first active one in stock, else the first active one. */
    private function primary(): ?Sku
    {
        $skus = $this->skus();

        return $skus->first(fn (Sku $sku): bool => $this->inStock($sku)) ?? $skus->first();
    }

    private function inStock(Sku $sku): bool
    {
        $smallest = $sku->relationLoaded('activeUnits') ? $sku->activeUnits->min('conversion_factor') : null;
        $available = $this->available($sku);

        return is_int($smallest) && $this->orderable($available, $smallest);
    }

    /** BR-INV02: the SKU's stock in base units; a bundle's is the bundles its components allow (D93). */
    private function available(Sku $sku): int
    {
        if ($this->resource->isBundle()) {
            return $sku->relationLoaded('bundleItems') ? app(BundleAvailability::class)->available($sku) : 0;
        }

        return $sku->relationLoaded('inventory') ? $sku->inventory->available ?? 0 : 0;
    }

    private function bundleSku(): ?Sku
    {
        $sku = $this->skus()->first();

        return $sku instanceof Sku && $sku->relationLoaded('bundleItems') ? $sku : null;
    }

    private function bundleAvailability(): int
    {
        $sku = $this->bundleSku();

        return $sku instanceof Sku ? $this->available($sku) : 0;
    }

    /**
     * BR-P15: the bundle's components, each saying whether it allows one bundle now.
     *
     * @return list<array<string, mixed>>
     */
    private function components(): array
    {
        $sku = $this->bundleSku();
        $availability = app(BundleAvailability::class);

        return $sku instanceof Sku ? array_values($sku->bundleItems->map(fn (BundleItem $item): array => [
            'product_name' => $item->componentUnit->sku->product->name,
            'slug' => $item->componentUnit->sku->product->slug,
            'sku_code' => $item->componentUnit->sku->code,
            'label' => $item->componentUnit->sku->name,
            'unit' => $item->componentUnit->unit->name,
            'quantity' => $item->quantity,
            'in_stock' => $availability->allows($item) >= 1,
        ])->all()) : [];
    }

    /** D93: the components bought on their own at the shopper's prices. */
    private function componentsTotal(): ?string
    {
        $sku = $this->bundleSku();

        return $sku instanceof Sku ? app(BundlePricer::class)->listedSum($sku->bundleItems, $this->pricing) : null;
    }

    /** D93: what buying the bundle saves this shopper, never below 0. */
    private function saving(): ?string
    {
        $sku = $this->bundleSku();
        $unit = $sku instanceof Sku && $sku->relationLoaded('activeUnits') ? $sku->activeUnits->first() : null;
        $total = $this->componentsTotal();

        if (! $unit instanceof SkuUnit || $total === null) {
            return null;
        }

        $saving = Money::subtract($total, app(PriceResolver::class)->listed($unit, $this->pricing)->basePrice);

        return Money::compare($saving, '0.00') > 0 ? $saving : '0.00';
    }

    /**
     * @return list<int>
     */
    private function optionValueIds(Sku $sku): array
    {
        if (! $sku->relationLoaded('optionValues') || ! $this->resource->relationLoaded('options')) {
            return [];
        }

        $ids = [];

        foreach ($this->resource->options as $option) {
            $value = $sku->optionValues->firstWhere('product_option_id', $option->id);

            if ($value instanceof ProductOptionValue) {
                $ids[] = $value->id;
            }
        }

        return $ids;
    }

    /**
     * The options with the values some active SKU uses (BR-P14).
     *
     * @return list<array{id: int, name: string, values: list<array{id: int, value: string, image_url: string|null}>}>
     */
    private function options(): array
    {
        if (! $this->resource->relationLoaded('options')) {
            return [];
        }

        $used = $this->skus()->flatMap(fn (Sku $sku): array => $this->optionValueIds($sku))->unique()->all();

        return array_values($this->resource->options->map(fn (ProductOption $option): array => [
            'id' => $option->id,
            'name' => $option->name,
            'values' => array_values($option->values
                ->filter(fn (ProductOptionValue $value): bool => in_array($value->id, $used, true))
                ->map(fn (ProductOptionValue $value): array => ['id' => $value->id, 'value' => $value->value, 'image_url' => $value->relationLoaded('image') ? $value->image?->url() : null])
                ->all()),
        ])->all());
    }

    /**
     * The lowest and highest default-unit price over the active SKUs, at the shopper's prices.
     *
     * @return array{min: string, max: string}|null
     */
    private function priceRange(): ?array
    {
        $prices = [];

        foreach ($this->skus() as $sku) {
            $units = $sku->relationLoaded('activeUnits') ? $sku->activeUnits : new Collection;
            $unit = $units->firstWhere('is_default', true) ?? $units->first();

            if ($unit instanceof SkuUnit) {
                $listed = $unit->relationLoaded('tiers') ? app(PriceResolver::class)->listed($unit, $this->pricing) : null;
                $prices[] = $listed->basePrice ?? $unit->base_price;
            }
        }

        if ($prices === []) {
            return null;
        }

        usort($prices, Money::compare(...));

        return ['min' => $prices[0], 'max' => $prices[count($prices) - 1]];
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
