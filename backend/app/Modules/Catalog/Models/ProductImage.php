<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * BR-P07: one main image plus gallery images. A partial unique index allows one main per product.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $path
 * @property bool $is_main
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 */
final class ProductImage extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    protected $attributes = [
        'is_main' => false,
        'sort_order' => 0,
    ];

    protected static function newFactory(): ProductImageFactory
    {
        return ProductImageFactory::new();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
