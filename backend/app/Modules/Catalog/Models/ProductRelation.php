<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductRelationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-directional typed link between two products of a tenant (BR-P10).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $related_product_id
 * @property RelationType $type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read Product $relatedProduct
 */
final class ProductRelation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductRelationFactory> */
    use HasFactory;

    protected static function newFactory(): ProductRelationFactory
    {
        return ProductRelationFactory::new();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RelationType::class,
        ];
    }
}
