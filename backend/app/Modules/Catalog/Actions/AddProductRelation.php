<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;

/**
 * BR-P10: links a product to another product of the same tenant (`revision_of`, `companion`).
 * One-directional; the reverse is displayed automatically.
 */
final readonly class AddProductRelation
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Product $product, int $relatedProductId, RelationType $type): ProductRelation
    {
        if ($relatedProductId === $product->id) {
            throw InvalidCatalogInput::field('related_product_id', 'لا يمكن ربط المنتج بنفسه');
        }

        if (Product::query()->whereKey($relatedProductId)->doesntExist()) {
            throw InvalidCatalogInput::unknownReference('related_product_id');
        }

        $exists = ProductRelation::query()
            ->where('product_id', $product->id)
            ->where('related_product_id', $relatedProductId)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            throw CatalogConflict::duplicateRelation();
        }

        $relation = ProductRelation::query()->create([
            'product_id' => $product->id,
            'related_product_id' => $relatedProductId,
            'type' => $type,
        ]);

        $this->audit->log('product_relation.added', $relation, null, [
            'product_id' => $product->id, 'related_product_id' => $relatedProductId, 'type' => $type->value,
        ]);

        return $relation->load('relatedProduct');
    }
}
