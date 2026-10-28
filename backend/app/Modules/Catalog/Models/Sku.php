<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BR-P02, BR-P03. A SKU is sold in one or more units (BR-U02, V2 Task 13, D91), each with its own factor, prices, MOQ and
 * tiers (`units`, BR-U04); stock is kept per SKU in its base unit (BR-U03, BR-INV02).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $code
 * @property string|null $name
 * @property int $base_unit_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read Unit $baseUnit
 * @property-read Collection<int, SkuUnit> $units
 * @property-read Collection<int, SkuUnit> $activeUnits
 * @property-read SkuUnit|null $defaultUnit
 * @property-read SkuUnit|null $smallestUnit
 * @property-read SkuInventory|null $inventory
 */
final class Sku extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkuFactory> */
    use HasFactory;

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
}
