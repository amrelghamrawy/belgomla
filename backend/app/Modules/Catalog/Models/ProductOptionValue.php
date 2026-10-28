<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Observers\FacetCacheObserver;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductOptionValueFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-P14: one value of a variant option («أزرق»), unique within the option, with an optional product image as its
 * storefront swatch (`image_id`, one image per value at most).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_option_id
 * @property string $value
 * @property int $sort_order
 * @property int|null $image_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ProductOption $option
 * @property-read ProductImage|null $image
 */
#[ObservedBy(FacetCacheObserver::class)]
final class ProductOptionValue extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductOptionValueFactory> */
    use HasFactory;

    protected $attributes = [
        'sort_order' => 0,
        'image_id' => null,
    ];

    protected static function newFactory(): ProductOptionValueFactory
    {
        return ProductOptionValueFactory::new();
    }

    /**
     * @return BelongsTo<ProductOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /**
     * @return BelongsTo<ProductImage, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'image_id' => 'integer',
        ];
    }
}
