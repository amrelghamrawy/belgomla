<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderReturnItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned line: which order line came back, how many selling units (and base units, what went back into
 * stock), priced at the order line's snapshot `unit_price` (D74).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property int $quantity selling units
 * @property int $quantity_base base units restocked (BR-R03)
 * @property string $unit_price
 * @property string $line_total
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrderReturn $orderReturn
 * @property-read OrderItem $orderItem
 */
final class OrderReturnItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderReturnItemFactory> */
    use HasFactory;

    protected static function newFactory(): OrderReturnItemFactory
    {
        return OrderReturnItemFactory::new();
    }

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
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
            'quantity' => 'integer',
            'quantity_base' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
