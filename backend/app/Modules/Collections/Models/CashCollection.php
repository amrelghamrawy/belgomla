<?php

declare(strict_types=1);

namespace App\Modules\Collections\Models;

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CashCollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-C02: one cash collection of an order (table `collections`; named apart from Laravel's Collection). Voided with
 * a reason, never deleted. `recorded_by` / `voided_by` are tenant user ids (null = system); Tenancy models stay
 * inside Tenancy, so there is no relation to them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property string $amount
 * @property CollectionMethod $method
 * @property CarbonImmutable $collected_at
 * @property int|null $recorded_by
 * @property string|null $note
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 */
final class CashCollection extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CashCollectionFactory> */
    use HasFactory;

    protected $table = 'collections';

    /** @var array<string, string> */
    protected $attributes = ['method' => 'cash'];

    protected static function newFactory(): CashCollectionFactory
    {
        return CashCollectionFactory::new();
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
            'amount' => 'decimal:2',
            'method' => CollectionMethod::class,
            'collected_at' => 'datetime',
            'recorded_by' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }
}
