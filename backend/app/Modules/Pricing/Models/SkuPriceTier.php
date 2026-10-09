<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SkuPriceTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-PR03..05: one quantity tier of a SKU, in its selling unit (BR-U04). `unit_price` is always the
 * absolute price; `discount_percent` is the % off cover price it was derived from, for display (BR-PR10).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sku_id
 * @property int $min_quantity
 * @property string $unit_price
 * @property string|null $discount_percent
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Sku $sku
 */
final class SkuPriceTier extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkuPriceTierFactory> */
    use HasFactory;

    protected static function newFactory(): SkuPriceTierFactory
    {
        return SkuPriceTierFactory::new();
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }
}
