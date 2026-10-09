<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-INV06: one change to On Hand, with the resulting On Hand for traceability. Append-only like the
 * audit log: written only by Inventory's StockLedger, never updated or deleted. Reservation changes are
 * not movements.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sku_id
 * @property MovementType $type
 * @property int $quantity
 * @property int $on_hand_after
 * @property int|null $order_id
 * @property string|null $reason
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property CarbonImmutable $created_at
 * @property-read Sku $sku
 */
final class InventoryMovement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected static function newFactory(): InventoryMovementFactory
    {
        return InventoryMovementFactory::new();
    }

    /**
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
            'type' => MovementType::class,
            'quantity' => 'integer',
            'on_hand_after' => 'integer',
            'order_id' => 'integer',
            'actor_type' => ActorType::class,
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
