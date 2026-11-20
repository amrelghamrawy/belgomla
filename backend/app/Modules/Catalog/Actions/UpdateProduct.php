<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Exceptions\BundleRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Catalog\Services\ProductSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Replaces product fields (not status, not stock), its options and SKUs (BR-P14, through SaveProductSkus: a SKU left out
 * is deleted, or set inactive when it has history, BR-P05; a new SKU gets its opening stock), and the pre-order settings
 * when sent (D88). Values of archived custom fields, and of
 * fields bound to another section than the (new) category's section, are carried over (BR-P08, D69). The `product.updated` audit record holds only what changed in product and SKU
 * identity fields and its units (`sku.units`: which units, factors, default; D91); each unit's prices, MOQ and tiers go
 * through SaveSkuUnits and Pricing's UpdateSkuPricing, which audits them per unit as `sku.price_changed` /
 * `sku.moq_changed` / `sku.tiers_changed` (BR-L). A unit's tiers not sent are kept; a unit not sent is archived.
 * Inside an audit batch (the catalog import, V2 Task 06) every record carries the batch id, and a product whose
 * own fields did not change gets no `product.updated` record, so re-importing a file does not flood the log.
 * A bundle is edited through UpdateBundle (`409 is_bundle`, BR-P15, D93).
 * Owns its transaction.
 */
final readonly class UpdateProduct
{
    public function __construct(
        private ProductReferences $references,
        private CustomFieldValidator $fields,
        private ProductSnapshot $snapshot,
        private SaveProductSkus $skus,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $product, ProductData $data, ?string $batchId = null): Product
    {
        if ($product->isBundle()) {
            throw BundleRefused::isBundle();
        }

        $product->load(ProductSnapshot::RELATIONS);
        $category = $this->references->check($data, $product);
        $matches = $this->references->matches($data, $product);
        $customFields = $this->fields->validate($data->customFields, $category->section_id, $product->custom_fields);
        $old = $this->snapshot->of($product, withPricing: false);

        return DB::transaction(function () use ($product, $data, $customFields, $matches, $old, $batchId): Product {
            $product->update([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'description' => $data->description,
                'custom_fields' => $customFields,
                ...($data->preorder?->attributes() ?? []), // D88: kept when not sent
            ]);

            $this->skus->handle($product, $data, $matches, null, $batchId);

            $product->load(ProductSnapshot::RELATIONS);

            $new = $this->snapshot->of($product, withPricing: false);

            if ($batchId === null || ! $this->same($old, $new)) {
                $this->audit->log('product.updated', $product, $old, $new, $batchId);
            }

            return $product;
        });
    }

    /**
     * Snapshots are equal; custom fields compare by key, since JSONB hands keys back in its own order.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function same(array $old, array $new): bool
    {
        return $this->sortedFields($old) === $this->sortedFields($new);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function sortedFields(array $snapshot): array
    {
        $fields = $snapshot['custom_fields'] ?? null;

        if (is_array($fields)) {
            ksort($fields);
            $snapshot['custom_fields'] = $fields;
        }

        return $snapshot;
    }
}
