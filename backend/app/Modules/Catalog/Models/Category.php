<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Observers\CategoryObserver;
use App\Modules\Catalog\Observers\FacetCacheObserver;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Category tree of unlimited depth (BR-P06). `path` is the materialized "/1/7/23/" chain, own id last.
 * `kind` is set on roots and copied down the tree like `path` (D68).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string $path
 * @property CategoryKind $kind
 * @property int $sort_order
 * @property string|null $image_path
 * @property ArchiveStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Product> $products
 */
#[ObservedBy([CategoryObserver::class, FacetCacheObserver::class])]
final class Category extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
        'path' => '/',
        'kind' => 'general',
    ];

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : Storage::disk('public')->url($this->image_path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArchiveStatus::class,
            'kind' => CategoryKind::class,
            'sort_order' => 'integer',
        ];
    }
}
