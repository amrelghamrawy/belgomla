<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderItemChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-O12, BR-O13: one change to an order line after placement, append-only (07-domain-model.md §7). Quantities are
 * selling units: `added` has only the new quantity and price, `removed` only the old ones, `quantity` both, and
 * `delivered_short` the ordered (old) and delivered (new) quantities at the line's price.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int|null $order_item_id
 * @property OrderItemChangeType $change
 * @property int|null $old_quantity
 * @property int|null $new_quantity
 * @property string|null $unit_price_before
 * @property string|null $unit_price_after
 * @property int|null $actor_id tenant_users.id, null = system
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read OrderItem|null $orderItem
 */
final class OrderItemChange extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderItemChangeFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected static function newFactory(): OrderItemChangeFactory
    {
        return OrderItemChangeFactory::new();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change' => OrderItemChangeType::class,
            'old_quantity' => 'integer',
            'new_quantity' => 'integer',
            'unit_price_before' => 'decimal:2',
            'unit_price_after' => 'decimal:2',
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
