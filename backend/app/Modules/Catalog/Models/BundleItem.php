<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BundleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a bundle (BR-P15, V2 Task 15, D93): `quantity` of a SKU unit of a simple product go into one bundle.
 * The unit is unique within the bundle; `sort_order` is the merchant's order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $bundle_sku_id
 * @property int $component_sku_unit_id
 * @property int $quantity
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Sku $bundleSku
 * @property-read SkuUnit $componentUnit
 */
final class BundleItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BundleItemFactory> */
    use HasFactory;

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected static function newFactory(): BundleItemFactory
    {
        return BundleItemFactory::new();
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function bundleSku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'bundle_sku_id');
    }

    /**
     * @return BelongsTo<SkuUnit, $this>
     */
    public function componentUnit(): BelongsTo
    {
        return $this->belongsTo(SkuUnit::class, 'component_sku_unit_id');
    }

    /** BR-U03: the component's base units one bundle takes. */
    public function baseQuantity(): int
    {
        return $this->quantity * $this->componentUnit->conversion_factor;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
