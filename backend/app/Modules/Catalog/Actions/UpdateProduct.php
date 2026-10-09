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
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use Illuminate\Support\Facades\DB;

/**
 * Replaces product and SKU fields (not status, not stock). Values of archived custom fields are
 * carried over (BR-P08). The `product.updated` audit record holds only what changed in product and SKU
 * identity fields; prices, MOQ and tiers go through Pricing's UpdateSkuPricing, which audits them as
 * `sku.price_changed` / `sku.moq_changed` / `sku.tiers_changed` (BR-L). Tiers not sent are kept.
 * Owns its transaction.
 */
final readonly class UpdateProduct
{
    public function __construct(
        private ProductReferences $references,
        private CustomFieldValidator $fields,
        private ProductSnapshot $snapshot,
        private UpdateSkuPricing $pricing,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $product, ProductData $data): Product
    {
        $product->loadMissing('sku');
        $this->references->check($data, $product);
        $customFields = $this->fields->validate($data->customFields, $product->custom_fields);
        $old = $this->snapshot->of($product, withPricing: false);

        return DB::transaction(function () use ($product, $data, $customFields, $old): Product {
            $product->update([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'custom_fields' => $customFields,
            ]);

            $sku = $product->sku instanceof Sku ? $product->sku->fill($data->sku->attributes()) : $product->skus()->make($data->sku->attributes());
            $this->pricing->handle($sku, $data->sku->pricing);

            $product->load('sku.tiers');

            $this->audit->log('product.updated', $product, $old, $this->snapshot->of($product, withPricing: false));

            return $product;
        });
    }
}
