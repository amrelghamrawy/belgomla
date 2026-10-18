<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderReturnFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods a customer brought back after delivery (BR-R01..R06, V2 Task 01). It has no status: it is live or voided
 * (`voided_at`, with a reason, never deleted). `number` counts the returns of one order (1, 2, …). `refund_amount` is
 * what was paid back in cash when the order was already collected beyond its new net total (BR-R04).
 * `recorded_by` / `voided_by` are tenant user ids (null = system).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int $number
 * @property string $total
 * @property string $refund_amount
 * @property string $reason
 * @property CarbonImmutable $returned_at
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read Collection<int, OrderReturnItem> $items
 */
final class OrderReturn extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderReturnFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $attributes = ['refund_amount' => '0.00'];

    protected static function newFactory(): OrderReturnFactory
    {
        return OrderReturnFactory::new();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'total' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'returned_at' => 'datetime',
            'recorded_by' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }
}
