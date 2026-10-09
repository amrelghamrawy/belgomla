<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order with the price actually applied (BR-PR07). Every display column is a snapshot; `sku_id`
 * and `product_id` are references only and never used to re-price (BR-O05).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int $sku_id
 * @property int $product_id
 * @property string $product_name
 * @property string $sku_code
 * @property string $sku_name
 * @property string $unit_name
 * @property int $conversion_factor
 * @property int $quantity selling units
 * @property int $quantity_base base units, what the order reserves (BR-INV03)
 * @property string|null $cover_price
 * @property string $unit_price
 * @property string|null $discount_percent
 * @property int|null $tier_min_quantity
 * @property string $line_total
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read Sku $sku
 */
final class OrderItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The catalog SKU the line was ordered from, for stock moves (BR-INV04, BR-INV05) only.
     *
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
            'conversion_factor' => 'integer',
            'quantity' => 'integer',
            'quantity_base' => 'integer',
            'cover_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'tier_min_quantity' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }
}
