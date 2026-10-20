<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's order (07-domain-model.md §7). Its lines and delivery details are snapshots (BR-PR07, BR-O10),
 * its lines change only while it is pending or confirmed (BR-O12) and on a short delivery (BR-O13), each change kept
 * in `itemChanges`; goods brought back are returns (BR-R01), which lower the net total. Routes address it by its per-tenant `number` (BR-O09).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $number
 * @property int $customer_id
 * @property OrderStatus $status
 * @property string $items_total
 * @property DiscountType|null $discount_type the manual order discount (BR-PR08, D85)
 * @property string|null $discount_value EGP off or % off the items total
 * @property string $discount_amount what the order discount takes off: order_total = items_total − discount_amount (BR-O08)
 * @property string|null $discount_reason
 * @property string $order_total
 * @property string $returned_total Σ live returns (BR-R05)
 * @property string $collected_amount
 * @property CollectionStatus $collection_status
 * @property string $delivery_name
 * @property string $delivery_phone
 * @property array<string, string|null> $delivery_address
 * @property string|null $customer_note
 * @property string|null $cancel_reason
 * @property CancelledBy|null $cancelled_by_type
 * @property ActorType $placed_by_type the customer at checkout, or a dashboard user on their behalf (BR-O11)
 * @property int|null $placed_by_id customers.id or tenant_users.id, by placed_by_type
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $due_at delivered_at + the customer's payment terms at delivery (BR-C06)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 * @property-read Collection<int, OrderItem> $items every line, removed ones included (BR-O12)
 * @property-read Collection<int, OrderItem> $liveItems
 * @property-read Collection<int, OrderItemChange> $itemChanges
 * @property-read Collection<int, OrderStatusHistory> $history
 * @property-read Collection<int, OrderReturn> $returns
 */
final class Order extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $attributes = [
        'discount_amount' => '0.00',
        'returned_total' => '0.00',
        'collected_amount' => '0.00',
        'collection_status' => 'unpaid',
        'placed_by_type' => 'customer',
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
     * The lines the merchant did not remove (BR-O12): what the order charges, holds and delivers.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function liveItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * Line changes after placement, oldest first (BR-O12, BR-O13).
     *
     * @return HasMany<OrderItemChange, $this>
     */
    public function itemChanges(): HasMany
    {
        return $this->hasMany(OrderItemChange::class)->orderBy('id');
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
     * Oldest first, voided ones included (BR-R06).
     *
     * @return HasMany<OrderReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->orderBy('number');
    }

    /** BR-O08: what the customer owes for the order once returns are taken off, as "150.00". */
    public function netTotal(): string
    {
        return Money::subtract($this->order_total, $this->returned_total);
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
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'order_total' => 'decimal:2',
            'returned_total' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'collection_status' => CollectionStatus::class,
            'delivery_address' => 'array',
            'cancelled_by_type' => CancelledBy::class,
            'placed_by_type' => ActorType::class,
            'placed_by_id' => 'integer',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }
}
