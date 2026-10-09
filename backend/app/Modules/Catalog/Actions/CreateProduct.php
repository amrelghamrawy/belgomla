<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Catalog\Services\ProductSnapshot;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use Illuminate\Support\Facades\DB;

/**
 * BR-P02: creates the product and its single SKU in one transaction (owned here), audited. The SKU's
 * prices, MOQ and tiers are validated and written by Pricing's UpdateSkuPricing (BR-PR10, BR-PR05).
 * Opening stock is Task 3 (Inventory).
 */
final readonly class CreateProduct
{
    public function __construct(
        private ProductReferences $references,
        private CustomFieldValidator $fields,
        private ProductSnapshot $snapshot,
        private UpdateSkuPricing $pricing,
        private AuditLogger $audit,
    ) {}

    public function handle(ProductData $data, ?int $createdBy = null): Product
    {
        $this->references->check($data);
        $customFields = $this->fields->validate($data->customFields);

        return DB::transaction(function () use ($data, $customFields, $createdBy): Product {
            $product = Product::query()->create([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'status' => $data->status ?? ProductStatus::Active,
                'custom_fields' => $customFields,
                'created_by' => $createdBy,
            ]);

            $this->pricing->handle($product->skus()->make($data->sku->attributes()), $data->sku->pricing);
            $product->load('sku.tiers');

            $this->audit->log('product.created', $product, null, $this->snapshot->of($product));

            return $product;
        });
    }
}
