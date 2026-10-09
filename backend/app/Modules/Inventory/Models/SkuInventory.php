<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SkuInventoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-INV02, BR-U03: the stock of one SKU in base units; 1:1 with `skus`, keyed by `sku_id`. Checkout locks
 * this row (`FOR UPDATE`). Written only by Inventory's StockLedger.
 *
 * @property int $sku_id
 * @property int $tenant_id
 * @property int $on_hand
 * @property int $reserved
 * @property-read int $available
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Sku $sku
 */
final class SkuInventory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SkuInventoryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'sku_inventory';

    protected $primaryKey = 'sku_id';

    protected $attributes = [
        'on_hand' => 0,
        'reserved' => 0,
    ];

    protected static function newFactory(): SkuInventoryFactory
    {
        return SkuInventoryFactory::new();
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * BR-INV02: Available = On Hand − Reserved.
     *
     * @return Attribute<int, never>
     */
    protected function available(): Attribute
    {
        return Attribute::get(fn (): int => $this->on_hand - $this->reserved);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
        ];
    }
}
