<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use Illuminate\Support\Facades\DB;

/**
 * BR-P11 / D30: copies name, category, description, custom fields, units, prices and MOQ into a new
 * `inactive` product with a new SKU code ("{code}-COPY", "-COPY-2", ...) and its own slug. Images,
 * relations and stock are not copied (stock starts at zero with Task 3). Owns its transaction.
 */
final readonly class DuplicateProduct
{
    private const int CODE_LENGTH = 60;

    public function __construct(private AuditLogger $audit) {}

    public function handle(Product $source, ?int $createdBy = null): Product
    {
        $source->loadMissing('sku');

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
                $copy->skus()->create([
                    ...$source->sku->only(['name', 'selling_unit_id', 'base_unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq']),
                    'code' => $this->nextCode($source->sku->code),
                ]);
            }

            $copy->load('sku');

            $this->audit->log('product.duplicated', $copy, null, ['source_product_id' => $source->id, 'sku.code' => $copy->sku?->code]);

            return $copy;
        });
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
