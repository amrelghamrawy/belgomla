<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-P02, BR-P03, BR-U02, BR-PR01, BR-PR10, BR-MOQ01. Prices and MOQ are per selling unit (BR-U04).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $code
 * @property string|null $name
 * @property int $selling_unit_id
 * @property int $base_unit_id
 * @property int $conversion_factor
 * @property string|null $cover_price
 * @property string $base_price
 * @property string|null $base_price_percent
 * @property int $moq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read Unit $sellingUnit
 * @property-read Unit $baseUnit
 */
final class Sku extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkuFactory> */
    use HasFactory;

    protected $attributes = [
        'conversion_factor' => 1,
        'moq' => 1,
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
    public function sellingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'selling_unit_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_factor' => 'integer',
            'cover_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'base_price_percent' => 'decimal:2',
            'moq' => 'integer',
        ];
    }
}
