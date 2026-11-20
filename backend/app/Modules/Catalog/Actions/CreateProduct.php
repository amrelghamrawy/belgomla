<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Catalog\Services\ProductSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * BR-P02, BR-P14: creates the product with its options and SKUs (one without options, one per variant, D92) in one
 * transaction (owned here), audited. SaveProductSkus writes them; each SKU's units (D91) go through SaveSkuUnits, their
 * prices, MOQ and tiers validated and written by Pricing's UpdateSkuPricing (BR-PR10, BR-PR05). Each SKU's opening stock
 * is written by Inventory's RecordOpeningStock in the same transaction (an `opening` movement, BR-INV06); the
 * `product.created` audit records their total as `opening_stock`. The catalog import passes
 * its audit batch id (V2 Task 06).
 */
final readonly class CreateProduct
{
    public function __construct(
        private ProductReferences $references,
        private CustomFieldValidator $fields,
        private ProductSnapshot $snapshot,
        private SaveProductSkus $skus,
        private AuditLogger $audit,
    ) {}

    public function handle(ProductData $data, ?int $createdBy = null, ?string $batchId = null): Product
    {
        $category = $this->references->check($data);
        $customFields = $this->fields->validate($data->customFields, $category->kind);

        return DB::transaction(function () use ($data, $customFields, $createdBy, $batchId): Product {
            $product = Product::query()->create([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'status' => $data->status ?? ProductStatus::Active,
                'custom_fields' => $customFields,
                'created_by' => $createdBy,
                ...($data->preorder?->attributes() ?? []), // D88: pre-orders off unless sent
            ]);

            $this->skus->handle($product, $data, array_fill(0, count($data->skus), null), $createdBy);
            $product->load(ProductSnapshot::RELATIONS);

            $openingStock = array_sum(array_map(fn (SkuData $sku): int => $sku->openingStock, $data->skus));
            $this->audit->log('product.created', $product, null, [...$this->snapshot->of($product), 'opening_stock' => $openingStock], $batchId);

            return $product;
        });
    }
}
