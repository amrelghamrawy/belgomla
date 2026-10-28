<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SkuStatus;
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
 * BR-P01..P04. A product has 1..N SKUs (BR-P02): one without options, one per variant when it has options (BR-P14,
 * V2 Task 14, D92; `options`, at most two). A Coming Soon product may take pre-orders
 * (`preorder_enabled`, V2 Task 10, D88).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProductStatus $status
 * @property array<string, int|float|string|bool> $custom_fields select values are option ids
 * @property bool $preorder_enabled customers may pre-order it while it is Coming Soon (BR-P04, BR-O14, D88)
 * @property CarbonImmutable|null $expected_at the day the merchant expects it, shown on the storefront
 * @property int|null $preorder_limit at most this many selling units over every open pre-order; null = no cap
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Category $category
 * @property-read Collection<int, Sku> $skus
 * @property-read Collection<int, Sku> $activeSkus
 * @property-read Collection<int, ProductOption> $options
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
        'preorder_enabled' => false,
        'expected_at' => null,
        'preorder_limit' => null,
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
     * Every SKU (variant) of the product, active and inactive, in the merchant's order (BR-P02, BR-P14).
     *
     * @return HasMany<Sku, $this>
     */
    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class)->orderBy('skus.sort_order')->orderBy('skus.id');
    }

    /**
     * The SKUs that can be shown and ordered (BR-P14).
     *
     * @return HasMany<Sku, $this>
     */
    public function activeSkus(): HasMany
    {
        return $this->skus()->where('skus.status', SkuStatus::Active);
    }

    /**
     * The variant options (D92), in the merchant's order.
     *
     * @return HasMany<ProductOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
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
            'preorder_enabled' => 'boolean',
            'expected_at' => 'datetime', // a date column: the day only (Y-m-d), *_at columns cast to datetime
            'preorder_limit' => 'integer',
        ];
    }
}
