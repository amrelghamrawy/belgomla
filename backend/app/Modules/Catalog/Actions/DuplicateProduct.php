<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Support\Facades\DB;

/**
 * BR-P11 / D30: copies name, category, description, custom fields, units, prices, tiers and MOQ into a new
 * `inactive` product with a new SKU code ("{code}-COPY", "-COPY-2", ...) and its own slug. Images,
 * relations and stock are not copied: the copy gets an empty stock row through Inventory's
 * RecordOpeningStock. Prices and tiers are copied as stored, through Pricing's UpdateSkuPricing. Owns its
 * transaction.
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
        $source->loadMissing('sku.tiers');

        return DB::transaction(function () use ($source, $createdBy): Product {
            $copy = Product::query()->create([
                'category_id' => $source->category_id,
                'name' => $source->name,
                'description' => $source->description,
                'status' => ProductStatus::Inactive,
                'custom_fields' => $source->custom_fields,
                'created_by' => $createdBy,
            ]);

            if ($source->sku instanceof Sku) {
                $this->copySku($source->sku, $copy);
            }

            $copy->load('sku');

            $this->audit->log('product.duplicated', $copy, null, ['source_product_id' => $source->id, 'sku.code' => $copy->sku?->code]);

            return $copy;
        });
    }

    private function copySku(Sku $source, Product $copy): void
    {
        $sku = $copy->skus()->make([
            ...$source->only(['name', 'selling_unit_id', 'base_unit_id', 'conversion_factor']),
            'code' => $this->nextCode($source->code),
        ]);

        $sku = $this->pricing->handle($sku, new SkuPricingData(
            basePrice: $source->base_price,
            basePricePercent: $source->base_price_percent,
            moq: $source->moq,
            tiers: $this->prices->tiers($source),
            coverPrice: $source->cover_price,
        ));

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
