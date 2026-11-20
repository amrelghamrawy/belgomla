<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SkuUnitFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One unit a SKU is sold in (BR-U02, V2 Task 13, D91): the piece, the carton of 48, ... `conversion_factor` base units
 * make one (BR-U03). Its prices, MOQ and tiers are its own (BR-U04, BR-PR01, BR-MOQ01); stock is the SKU's. Exactly one
 * active unit of a SKU is the default (shown first). An archived unit can no longer be ordered; order lines keep it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sku_id
 * @property int $unit_id
 * @property int $conversion_factor
 * @property string $base_price
 * @property string|null $base_price_percent
 * @property string|null $cover_price
 * @property int $moq
 * @property bool $is_default
 * @property int $sort_order
 * @property ArchiveStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Sku $sku
 * @property-read Unit $unit
 * @property-read Collection<int, SkuPriceTier> $tiers
 */
final class SkuUnit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkuUnitFactory> */
    use HasFactory;

    protected $attributes = [
        'conversion_factor' => 1,
        'moq' => 1,
        'is_default' => false,
        'sort_order' => 0,
        'status' => 'active',
    ];

    protected static function newFactory(): SkuUnitFactory
    {
        return SkuUnitFactory::new();
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Quantity tiers, lowest threshold first. The table belongs to Pricing (BR-PR03); this relation is the only place
     * Catalog names a Pricing model, so the tiers can be eager-loaded with the unit.
     *
     * @return HasMany<SkuPriceTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(SkuPriceTier::class)->orderBy('min_quantity');
    }

    public function isActive(): bool
    {
        return $this->status === ArchiveStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_factor' => 'integer',
            'base_price' => 'decimal:2',
            'base_price_percent' => 'decimal:2',
            'cover_price' => 'decimal:2',
            'moq' => 'integer',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'status' => ArchiveStatus::class,
        ];
    }
}
