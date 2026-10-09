<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's order (07-domain-model.md §7). Its lines and delivery details are snapshots (BR-PR07, BR-O10),
 * its items never change after checkout (BR-O06). Routes address it by its per-tenant `number` (BR-O09).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $number
 * @property int $customer_id
 * @property OrderStatus $status
 * @property string $items_total
 * @property string $order_total
 * @property string $collected_amount
 * @property CollectionStatus $collection_status
 * @property string $delivery_name
 * @property string $delivery_phone
 * @property array<string, string|null> $delivery_address
 * @property string|null $customer_note
 * @property string|null $cancel_reason
 * @property CancelledBy|null $cancelled_by_type
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $history
 */
final class Order extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $attributes = [
        'collected_amount' => '0.00',
        'collection_status' => 'unpaid',
    ];

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * Oldest first (BR-O04).
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => OrderStatus::class,
            'items_total' => 'decimal:2',
            'order_total' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'collection_status' => CollectionStatus::class,
            'delivery_address' => 'array',
            'cancelled_by_type' => CancelledBy::class,
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
