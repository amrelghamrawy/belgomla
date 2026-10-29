<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BundlePricingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a bundle is priced (BR-P15, V2 Task 15, D93): `fixed` at `value`, or `percent_off_sum`, the components' base prices
 * × their quantities less `value` %. The resulting price is stored as the bundle unit's base price (BundlePricer), so the
 * PriceResolver prices a bundle like any SKU unit (groups, promotions, negotiated prices, BR-PR14, BR-PR15).
 *
 * @property int $bundle_sku_id
 * @property int $tenant_id
 * @property BundlePriceMode $mode
 * @property string $value a price (fixed) or a % (percent_off_sum)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Sku $bundleSku
 */
final class BundlePricing extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BundlePricingFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'bundle_pricing';

    protected $primaryKey = 'bundle_sku_id';

    protected static function newFactory(): BundlePricingFactory
    {
        return BundlePricingFactory::new();
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function bundleSku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'bundle_sku_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => BundlePriceMode::class,
            'value' => 'decimal:2',
        ];
    }
}
