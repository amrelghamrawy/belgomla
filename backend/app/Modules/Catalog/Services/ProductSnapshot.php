<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\BundlePricer;
use App\Modules\Pricing\Services\PriceResolver;

/**
 * The flat view of a product + its SKUs that audit records store (BR-L). A product with one SKU and no options keeps the
 * flat `sku.*` keys, so the AuditLogger keeps only the changed fields; `sku.units` lists the active units (D91). A product
 * with variants (BR-P14, V2 Task 14) has `options` (name and values) and `skus` (code, label, status, base unit and
 * units of each). `withPricing: false` leaves out each unit's prices, MOQ and tiers for `product.updated`, because
 * UpdateSkuPricing audits those itself. A bundle (BR-P15, D93) adds `bundle`: its components (`sku_unit_id`, `quantity`)
 * and pricing (`mode`, `value`).
 */
final readonly class ProductSnapshot
{
    /** What `of()` reads; load it once instead of lazily. */
    public const array RELATIONS = ['skus.activeUnits.tiers', 'options.values'];

    public function __construct(
        private PriceResolver $prices,
        private BundlePricer $bundles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function of(Product $product, bool $withPricing = true): array
    {
        $skus = $product->skus;
        $options = $product->options;
        $single = $options->isEmpty() && $skus->count() === 1 ? $skus->first() : null;

        return [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'description' => $product->description,
            'status' => $product->status->value,
            'custom_fields' => $product->custom_fields,
            'preorder_enabled' => $product->preorder_enabled,
            'expected_at' => $product->expected_at?->toDateString(),
            'preorder_limit' => $product->preorder_limit,
            ...($single instanceof Sku ? $this->flat($single, $withPricing) : []),
            ...($single instanceof Sku || $skus->isEmpty() ? [] : [
                'options' => $options->map(fn (ProductOption $option): array => [
                    'name' => $option->name,
                    'values' => $option->values->map(fn (ProductOptionValue $value): string => $value->value)->values()->all(),
                ])->values()->all(),
                'skus' => $skus->map(fn (Sku $sku): array => [
                    'code' => $sku->code,
                    'label' => $sku->name,
                    'status' => $sku->status->value,
                    'base_unit_id' => $sku->base_unit_id,
                    'units' => $this->units($sku, $withPricing),
                ])->values()->all(),
            ]),
        ];
    }

    /**
     * BR-P15: the snapshot of a bundle, with its components and pricing.
     *
     * @return array<string, mixed>
     */
    public function ofBundle(Product $product): array
    {
        $sku = $product->skus->first();
        $pricing = $sku instanceof Sku ? $this->bundles->pricingOf($sku) : null;

        return [
            ...$this->of($product),
            'bundle' => [
                'components' => $sku instanceof Sku ? $sku->bundleItems()->get()->map(fn (BundleItem $item): array => ['sku_unit_id' => $item->component_sku_unit_id, 'quantity' => $item->quantity])->values()->all() : [],
                'pricing' => $pricing instanceof \App\Modules\Pricing\Data\BundlePricingData ? ['mode' => $pricing->mode->value, 'value' => $pricing->value] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flat(Sku $sku, bool $withPricing): array
    {
        return [
            'sku.code' => $sku->code,
            'sku.base_unit_id' => $sku->base_unit_id,
            'sku.units' => $this->units($sku, $withPricing),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function units(Sku $sku, bool $withPricing): array
    {
        return array_values($sku->loadMissing('activeUnits')->activeUnits->map(fn (SkuUnit $unit): array => [
            'unit_id' => $unit->unit_id,
            'conversion_factor' => $unit->conversion_factor,
            'is_default' => $unit->is_default,
            ...($withPricing ? $this->pricing($unit) : []),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(SkuUnit $unit): array
    {
        return [
            'cover_price' => $unit->cover_price,
            'base_price' => $unit->base_price,
            'base_price_percent' => $unit->base_price_percent,
            'moq' => $unit->moq,
            'tiers' => array_map(
                fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'unit_price' => $tier->unitPrice, 'discount_percent' => $tier->discountPercent],
                $this->prices->tiers($unit->loadMissing('tiers')),
            ),
        ];
    }
}
