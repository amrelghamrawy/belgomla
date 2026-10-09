<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Casts\JsonObject;
use App\Modules\Catalog\Observers\ProductObserver;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BR-P01..P04. V1 invariant (app-enforced): exactly one SKU per product.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProductStatus $status
 * @property array<string, int|float|string|bool> $custom_fields select values are option ids
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Category $category
 * @property-read Collection<int, Sku> $skus
 * @property-read Sku|null $sku
 * @property-read Collection<int, ProductImage> $images
 * @property-read ProductImage|null $mainImage
 * @property-read Collection<int, ProductRelation> $relations
 * @property-read Collection<int, ProductRelation> $incomingRelations
 */
#[ObservedBy(ProductObserver::class)]
final class Product extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'custom_fields' => '{}',
    ];

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Sku, $this>
     */
    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    /**
     * The single V1 SKU (BR-P02).
     *
     * @return HasOne<Sku, $this>
     */
    public function sku(): HasOne
    {
        return $this->hasOne(Sku::class)->oldestOfMany();
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasOne<ProductImage, $this>
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_main', true);
    }

    /**
     * Relations this product declares (e.g. "I am the revision of X").
     *
     * @return HasMany<ProductRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(ProductRelation::class);
    }

    /**
     * Relations other products declare towards this one (the automatic reverse display, BR-P10).
     *
     * @return HasMany<ProductRelation, $this>
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(ProductRelation::class, 'related_product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'custom_fields' => JsonObject::class,
        ];
    }
}
