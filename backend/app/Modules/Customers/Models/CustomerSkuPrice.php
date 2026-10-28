<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerSkuPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-PR13 (V2 Task 11, D89): the unit price negotiated with one customer for one SKU, in its selling unit. It wins over
 * the base price, the tiers and the group discount at every quantity. One per customer and SKU.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $sku_id
 * @property int $sku_unit_id
 * @property string $unit_price
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 * @property-read Sku $sku
 * @property-read SkuUnit $skuUnit
 */
final class CustomerSkuPrice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerSkuPriceFactory> */
    use HasFactory;

    protected static function newFactory(): CustomerSkuPriceFactory
    {
        return CustomerSkuPriceFactory::new();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * The unit the price is negotiated for (D91).
     *
     * @return BelongsTo<SkuUnit, $this>
     */
    public function skuUnit(): BelongsTo
    {
        return $this->belongsTo(SkuUnit::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }
}
