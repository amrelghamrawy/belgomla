<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order with the price actually applied (BR-PR07). Every display column is a snapshot; `sku_id`
 * and `product_id` are references only and never used to re-price (BR-O05), except when the merchant edits the
 * quantity of a pending or confirmed order (BR-O12).
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
 * @property string $list_unit_price the price before the manual discount (BR-PR08, D85)
 * @property DiscountType|null $manual_discount_type
 * @property string|null $manual_discount_value per unit: EGP off or % off the list price
 * @property string $unit_price the price charged per unit, after the manual discount
 * @property string|null $discount_percent
 * @property int|null $tier_min_quantity
 * @property string $line_total = unit_price × the quantity charged: delivered_quantity once delivered (BR-O13), else quantity
 * @property int|null $delivered_quantity selling units that went out, set on delivery (BR-O13, D86)
 * @property bool $reserved whether the line holds stock: false on a pre-order line (BR-O14, D88)
 * @property int $converted_quantity selling units of a pre-order line already converted into a normal order
 * @property CarbonImmutable|null $removed_at the merchant removed the line (BR-O12): kept for history, out of every total
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

    protected $attributes = [
        'reserved' => true,
        'converted_quantity' => 0,
    ];

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

    /** BR-O14: what is left of a pre-order line to convert (the whole quantity on a normal order line). */
    public function remainingQuantity(): int
    {
        return $this->quantity - $this->converted_quantity;
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
            'list_unit_price' => 'decimal:2',
            'manual_discount_type' => DiscountType::class,
            'manual_discount_value' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'tier_min_quantity' => 'integer',
            'line_total' => 'decimal:2',
            'delivered_quantity' => 'integer',
            'removed_at' => 'datetime',
            'reserved' => 'boolean',
            'converted_quantity' => 'integer',
        ];
    }
}
