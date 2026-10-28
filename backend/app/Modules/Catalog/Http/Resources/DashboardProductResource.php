<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard view of a product with its SKUs (08-api-contract.md §6). `skus` lists every SKU, active and inactive, in the
 * merchant's order, each with its variant `label`, `status`, `sort_order` and `option_values` (texts in option order, as
 * the form sends them; BR-P14, V2 Task 14); `options` the variant options with their values and swatches; `sku` is the
 * first active SKU (else the first), standing for the product in lists. The detail endpoint also loads
 * `images` and `relations`; the list leaves them out. `custom_fields` is the raw stored map
 * (select values are option ids) because the dashboard form edits it. `sku.units` lists the active units the SKU is
 * sold in, the default first (V2 Task 13, D91), each with its tiers (with the % each was entered as) when they are
 * loaded, i.e. on the detail; `selling_unit`, `conversion_factor`, the prices and `moq` next to them are the default
 * unit's, for the lists. `sku.inventory`
 * (`on_hand, reserved, available` in base units, BR-INV02; no stock row = zeros). `preorder` holds the pre-order
 * settings (`enabled`, `expected_at` as Y-m-d, `limit`, D88).
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
            'preorder' => ['enabled' => $product->preorder_enabled, 'expected_at' => $product->expected_at?->toDateString(), 'limit' => $product->preorder_limit],
            'sku' => $this->primary(),
            'skus' => $this->whenLoaded('skus', fn (): array => $product->skus->map(fn (Sku $sku): array => $this->sku($sku))->values()->all(), []),
            'options' => $this->whenLoaded('options', fn (): array => $product->options->map($this->option(...))->values()->all(), []),
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
     * @return array{id: int, name: string, kind: string}|null
     */
    private function category(): ?array
    {
        $category = $this->resource->relationLoaded('category') ? $this->resource->category : null;

        return $category instanceof Category ? ['id' => $category->id, 'name' => $category->name, 'kind' => $category->kind->value] : null;
    }

    private function mainImage(): ?ProductImage
    {
        return $this->resource->relationLoaded('mainImage') ? $this->resource->mainImage : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function primary(): ?array
    {
        $skus = $this->resource->relationLoaded('skus') ? $this->resource->skus : null;
        $sku = $skus?->first(fn (Sku $sku): bool => $sku->isActive()) ?? $skus?->first();

        return $sku instanceof Sku ? $this->sku($sku) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function option(ProductOption $option): array
    {
        return [
            'id' => $option->id,
            'name' => $option->name,
            'sort_order' => $option->sort_order,
            'values' => $option->values->map(fn (ProductOptionValue $value): array => [
                'id' => $value->id,
                'value' => $value->value,
                'sort_order' => $value->sort_order,
                'image_id' => $value->image_id,
                'image_url' => $value->relationLoaded('image') ? $value->image?->url() : null,
            ])->values()->all(),
        ];
    }

    /**
     * The SKU's option values in the product's option order (BR-P14).
     *
     * @return list<string>
     */
    private function optionValues(Sku $sku): array
    {
        if (! $sku->relationLoaded('optionValues') || ! $this->resource->relationLoaded('options')) {
            return [];
        }

        $values = [];

        foreach ($this->resource->options as $option) {
            $value = $sku->optionValues->firstWhere('product_option_id', $option->id);

            if ($value instanceof ProductOptionValue) {
                $values[] = $value->value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function sku(Sku $sku): array
    {

        $units = $sku->relationLoaded('activeUnits') ? $sku->activeUnits : null;
        $default = $units?->firstWhere('is_default', true);

        return [
            'id' => $sku->id,
            'code' => $sku->code,
            'base_unit' => $this->unit($sku->relationLoaded('baseUnit') ? $sku->baseUnit : null),
            'selling_unit' => $this->unit($default?->relationLoaded('unit') === true ? $default->unit : null),
            'conversion_factor' => $default?->conversion_factor,
            'cover_price' => $default?->cover_price,
            'base_price' => $default?->base_price,
            'base_price_percent' => $default?->base_price_percent,
            'moq' => $default?->moq,
            'units' => $this->when($units !== null, fn (): array => $units?->map($this->skuUnit(...))->values()->all() ?? []),
            'inventory' => $this->when($sku->relationLoaded('inventory'), fn (): array => [
                'on_hand' => $sku->inventory->on_hand ?? 0,
                'reserved' => $sku->inventory->reserved ?? 0,
                'available' => $sku->inventory->available ?? 0,
            ]),
            'label' => $sku->name,
            'status' => $sku->status->value,
            'sort_order' => $sku->sort_order,
            'option_values' => $this->optionValues($sku),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skuUnit(SkuUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'unit' => $this->unit($unit->relationLoaded('unit') ? $unit->unit : null),
            'conversion_factor' => $unit->conversion_factor,
            'cover_price' => $unit->cover_price,
            'base_price' => $unit->base_price,
            'base_price_percent' => $unit->base_price_percent,
            'moq' => $unit->moq,
            'is_default' => $unit->is_default,
            'tiers' => $this->when($unit->relationLoaded('tiers'), fn (): array => array_map(fn (TierData $tier): array => [
                'min_quantity' => $tier->minQuantity,
                'unit_price' => $tier->unitPrice,
                'discount_percent' => $tier->discountPercent,
            ], app(PriceResolver::class)->tiers($unit))),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function unit(?Unit $unit): ?array
    {
        return $unit instanceof Unit ? ['id' => $unit->id, 'name' => $unit->name] : null;
    }
}
