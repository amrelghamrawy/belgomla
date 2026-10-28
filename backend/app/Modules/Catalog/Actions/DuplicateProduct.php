<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Support\Facades\DB;

/**
 * BR-P11 / D30: copies name, category, description, custom fields, the variant options and values (BR-P14, V2 Task 14)
 * and every SKU (status, label and option values kept) with its active units, prices, tiers and MOQ (D91) into a new
 * `inactive` product. Each SKU gets a new code ("{code}-COPY", "-COPY-2", ...); the product its own slug. Images,
 * relations and stock are not copied (so no value keeps a swatch): each SKU gets an empty stock row through Inventory's
 * RecordOpeningStock. Prices and tiers are copied as stored, through Pricing's UpdateSkuPricing. Owns its transaction.
 */
final readonly class DuplicateProduct
{
    private const int CODE_LENGTH = 60;

    public function __construct(
        private PriceResolver $prices,
        private UpdateSkuPricing $pricing,
        private RecordOpeningStock $openingStock,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $source, ?int $createdBy = null): Product
    {
        $source->loadMissing(['skus.activeUnits.tiers', 'skus.optionValues', 'options.values']);

        return DB::transaction(function () use ($source, $createdBy): Product {
            $copy = Product::query()->create([
                'category_id' => $source->category_id,
                'name' => $source->name,
                'description' => $source->description,
                'status' => ProductStatus::Inactive,
                'custom_fields' => $source->custom_fields,
                'created_by' => $createdBy,
            ]);

            $values = $this->copyOptions($source, $copy);

            foreach ($source->skus as $sku) {
                $this->copySku($sku, $copy, $values);
            }

            $copy->load('skus');

            $this->audit->log('product.duplicated', $copy, null, [
                'source_product_id' => $source->id,
                'sku.code' => $copy->skus->first()?->code,
                ...($copy->skus->count() > 1 ? ['skus' => $copy->skus->pluck('code')->all()] : []),
            ]);

            return $copy;
        });
    }

    /**
     * @return array<int, ProductOptionValue> source value id => its copy
     */
    private function copyOptions(Product $source, Product $copy): array
    {
        $values = [];

        foreach ($source->options as $option) {
            $copied = ProductOption::query()->create(['product_id' => $copy->id, ...$option->only(['name', 'sort_order'])]);

            foreach ($option->values as $value) {
                $values[$value->id] = ProductOptionValue::query()->create(['product_option_id' => $copied->id, ...$value->only(['value', 'sort_order'])]);
            }
        }

        return $values;
    }

    /**
     * @param  array<int, ProductOptionValue>  $values
     */
    private function copySku(Sku $source, Product $copy, array $values): void
    {
        $sku = $copy->skus()->create([
            ...$source->only(['name', 'base_unit_id', 'status', 'sort_order']),
            'code' => $this->nextCode($source->code),
        ]);

        foreach ($source->optionValues as $value) {
            $copied = $values[$value->id] ?? null;

            if ($copied instanceof ProductOptionValue) {
                DB::table('sku_option_values')->insert(['tenant_id' => $sku->tenant_id, 'sku_id' => $sku->id, 'product_option_id' => $copied->product_option_id, 'product_option_value_id' => $copied->id]);
            }
        }

        foreach ($source->activeUnits as $unit) {
            $this->pricing->handle($sku->units()->make($unit->only(['unit_id', 'conversion_factor', 'is_default', 'sort_order'])), new SkuPricingData(
                basePrice: $unit->base_price,
                basePricePercent: $unit->base_price_percent,
                moq: $unit->moq,
                tiers: $this->prices->tiers($unit),
                coverPrice: $unit->cover_price,
            ));
        }

        $this->openingStock->handle($sku, 0);
    }

    private function nextCode(string $code): string
    {
        $base = mb_substr($code, 0, self::CODE_LENGTH - 9).'-COPY';
        $candidate = $base;
        $attempt = 1;

        while (Sku::query()->where('code', $candidate)->exists()) {
            $attempt++;
            $candidate = "{$base}-{$attempt}";
        }

        return $candidate;
    }
}
