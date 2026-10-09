<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Catalog\Services\ProductSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Replaces product and SKU fields (not status, not stock). Values of archived custom fields are
 * carried over (BR-P08). The audit record holds only what changed, so a base price or MOQ change is
 * visible as `sku.base_price` / `sku.moq` (BR-L). Owns its transaction.
 */
final readonly class UpdateProduct
{
    public function __construct(
        private ProductReferences $references,
        private CustomFieldValidator $fields,
        private ProductSnapshot $snapshot,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $product, ProductData $data): Product
    {
        $product->loadMissing('sku');
        $this->references->check($data, $product);
        $customFields = $this->fields->validate($data->customFields, $product->custom_fields);
        $old = $this->snapshot->of($product);

        return DB::transaction(function () use ($product, $data, $customFields, $old): Product {
            $product->update([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'custom_fields' => $customFields,
            ]);

            if ($product->sku instanceof Sku) {
                $product->sku->update($data->sku->attributes());
            } else {
                $product->skus()->create($data->sku->attributes());
            }

            $product->load('sku');

            $this->audit->log('product.updated', $product, $old, $this->snapshot->of($product));

            return $product;
        });
    }
}
