<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-O04: one status change of an order, with who made it and an optional note. Append-only; `from_status`
 * is null for the creation (→ pending).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 */
final class OrderStatusHistory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected static function newFactory(): OrderStatusHistoryFactory
    {
        return OrderStatusHistoryFactory::new();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'actor_type' => ActorType::class,
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
