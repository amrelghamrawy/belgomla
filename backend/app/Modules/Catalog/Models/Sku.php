<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Observers\FacetCacheObserver;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BR-P02, BR-P03. A SKU is sold in one or more units (BR-U02, V2 Task 13, D91), each with its own factor, prices, MOQ and
 * tiers (`units`, BR-U04); stock is kept per SKU in its base unit (BR-U03, BR-INV02). A product has one SKU per
 * variant (BR-P14, V2 Task 14): `optionValues` names one value of every option of the product, `name` keeps the variant
 * label («أزرق / M», null without options) for snapshots, `status` hides one variant, `sort_order` is the form order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $code
 * @property string|null $name
 * @property int $base_unit_id
 * @property SkuStatus $status
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read Unit $baseUnit
 * @property-read Collection<int, SkuUnit> $units
 * @property-read Collection<int, SkuUnit> $activeUnits
 * @property-read SkuUnit|null $defaultUnit
 * @property-read SkuUnit|null $smallestUnit
 * @property-read SkuInventory|null $inventory
 * @property-read Collection<int, ProductOptionValue> $optionValues
 */
#[ObservedBy(FacetCacheObserver::class)]
final class Sku extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkuFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected static function newFactory(): SkuFactory
    {
        return SkuFactory::new();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * Every unit the SKU is or was sold in (BR-U02, D91), the default first, then by `sort_order`. Archived ones included.
     *
     * @return HasMany<SkuUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(SkuUnit::class)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The units that can be ordered now, the default first.
     *
     * @return HasMany<SkuUnit, $this>
     */
    public function activeUnits(): HasMany
    {
        return $this->units()->where('status', ArchiveStatus::Active);
    }

    /**
     * The unit shown first and used where one unit stands for the SKU (D91).
     *
     * @return HasOne<SkuUnit, $this>
     */
    public function defaultUnit(): HasOne
    {
        return $this->hasOne(SkuUnit::class)->where('is_default', true);
    }

    /**
     * D36 (V2 Task 13): the active unit with the fewest base units, whose MOQ × factor is the low-stock threshold.
     *
     * @return HasOne<SkuUnit, $this>
     */
    public function smallestUnit(): HasOne
    {
        return $this->hasOne(SkuUnit::class)->ofMany(['conversion_factor' => 'min', 'id' => 'min'], fn (Builder $units): Builder => $units->where('status', ArchiveStatus::Active));
    }

    /**
     * Stock in base units (BR-INV02). The table belongs to Inventory and is written only by its
     * StockLedger; this relation lets Catalog eager-load the stock with the SKU. No row means no stock.
     *
     * @return HasOne<SkuInventory, $this>
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(SkuInventory::class);
    }

    /**
     * The value of each option of the product this variant is (BR-P14), in option order once sorted by the caller.
     *
     * @return BelongsToMany<ProductOptionValue, $this>
     */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'sku_option_values')->withPivot('product_option_id');
    }

    public function isActive(): bool
    {
        return $this->status === SkuStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SkuStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
