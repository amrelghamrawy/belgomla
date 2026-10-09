<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Exceptions\BelowReserved;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\NotInTransaction;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of `sku_inventory` and `inventory_movements` (Tech §11). A service that writes, by
 * design (rules/02 §1 lists it under Services): every stock change in every module goes through here.
 *
 * Locking: every change works on a row locked with `SELECT … FOR UPDATE`, ordered by `sku_id` so two
 * transactions locking several SKUs always queue in the same order and cannot deadlock (domain §12).
 * `reserve`, `release` and `deduct` join the caller's transaction (checkout, order transitions) and
 * refuse to run outside one; `openingStock` and `adjust` open their own (nesting into the caller's).
 * A SKU without a stock row gets an empty one the first time it is locked.
 *
 * Only On Hand changes are movements (BR-INV06); reservation changes are not.
 */
final readonly class StockLedger
{
    public function __construct(private ReservationHolders $holders) {}

    /**
     * Locks the stock rows of the given SKUs (of the current tenant) in `sku_id` order.
     *
     * @param  list<int>  $skuIds
     * @return Collection<int, SkuInventory> keyed by sku_id
     */
    public function lock(array $skuIds): Collection
    {
        $this->assertInTransaction('lock');

        $ids = array_values(array_unique($skuIds));
        sort($ids);

        $rows = $this->selectForUpdate($ids);

        if ($rows->count() === count($ids)) {
            return $rows;
        }

        SkuInventory::query()->insertOrIgnoreUsing(
            ['sku_id', 'tenant_id', 'created_at', 'updated_at'],
            Sku::query()->whereKey(array_diff($ids, $rows->keys()->all()))->select(['skus.id', 'skus.tenant_id'])->selectRaw('now(), now()'),
        );

        return $this->selectForUpdate($ids);
    }

    /** Opening stock of a new SKU, as an `opening` movement (BR-INV06). Zero creates the row only. */
    public function openingStock(Sku $sku, int $quantity, ?int $actorId = null): void
    {
        $this->assertNotNegative($quantity);

        DB::transaction(function () use ($sku, $quantity, $actorId): void {
            $row = $this->row($sku);

            if ($quantity === 0) {
                return;
            }

            $row->on_hand += $quantity;
            $row->save();

            $this->record($sku, MovementType::Opening, $quantity, $row->on_hand, $actorId);
        });
    }

    /**
     * A merchant sets On Hand to a counted value (BR-INV06 `adjustment`). Never below Reserved (BR-INV08);
     * setting the current value changes nothing and returns null (D35).
     */
    public function adjust(Sku $sku, int $newOnHand, string $reason, ?int $actorId = null): ?InventoryMovement
    {
        $this->assertNotNegative($newOnHand);

        return DB::transaction(function () use ($sku, $newOnHand, $reason, $actorId): ?InventoryMovement {
            $row = $this->row($sku);

            if ($newOnHand === $row->on_hand) {
                return null;
            }

            if ($newOnHand < $row->reserved) {
                throw BelowReserved::for($newOnHand, $row->reserved, $this->holders->ordersHolding($sku));
            }

            $change = $newOnHand - $row->on_hand;
            $row->on_hand = $newOnHand;
            $row->save();

            return $this->record($sku, MovementType::Adjustment, $change, $newOnHand, $actorId, $reason);
        });
    }

    /** BR-INV03: holds base units for a new order; never more than Available (BR-INV01). */
    public function reserve(Sku $sku, int $quantityBase): void
    {
        $this->assertInTransaction('reserve');
        $this->assertPositive($quantityBase);

        $row = $this->row($sku);

        if ($row->available < $quantityBase) {
            throw InsufficientStock::for($sku->id, $row->available);
        }

        $row->reserved += $quantityBase;
        $row->save();
    }

    /** BR-INV04: a cancelled order gives its reservation back. */
    public function release(Sku $sku, int $quantityBase): void
    {
        $this->assertInTransaction('release');
        $this->assertPositive($quantityBase);

        $row = $this->row($sku);
        $this->assertReserved($row, $quantityBase);

        $row->reserved -= $quantityBase;
        $row->save();
    }

    /** BR-INV05: a delivered order leaves the stock, as a negative `sale` movement linked to it. */
    public function deduct(Sku $sku, int $quantityBase, int $orderId, ?int $actorId = null): InventoryMovement
    {
        $this->assertInTransaction('deduct');
        $this->assertPositive($quantityBase);

        $row = $this->row($sku);
        $this->assertReserved($row, $quantityBase);

        $row->on_hand -= $quantityBase;
        $row->reserved -= $quantityBase;
        $row->save();

        return $this->record($sku, MovementType::Sale, -$quantityBase, $row->on_hand, $actorId, orderId: $orderId);
    }

    /**
     * @param  list<int>  $ids  sorted
     * @return Collection<int, SkuInventory>
     */
    private function selectForUpdate(array $ids): Collection
    {
        return SkuInventory::query()->whereIn('sku_id', $ids)->orderBy('sku_id')->lockForUpdate()->get()->keyBy('sku_id');
    }

    private function row(Sku $sku): SkuInventory
    {
        return $this->lock([$sku->id])->get($sku->id) ?? throw new RuntimeException("SKU {$sku->id} is not a SKU of the current tenant.");
    }

    private function record(Sku $sku, MovementType $type, int $quantity, int $onHandAfter, ?int $actorId, ?string $reason = null, ?int $orderId = null): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'sku_id' => $sku->id,
            'type' => $type,
            'quantity' => $quantity,
            'on_hand_after' => $onHandAfter,
            'order_id' => $orderId,
            'reason' => $reason,
            'actor_type' => $actorId === null ? ActorType::System : ActorType::TenantUser,
            'actor_id' => $actorId,
        ]);
    }

    private function assertInTransaction(string $operation): void
    {
        if (DB::transactionLevel() === 0) {
            throw NotInTransaction::for($operation);
        }
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException("Stock quantities must be positive, {$quantity} given.");
        }
    }

    private function assertNotNegative(int $quantity): void
    {
        if ($quantity < 0) {
            throw new RuntimeException("Stock cannot be negative, {$quantity} given.");
        }
    }

    private function assertReserved(SkuInventory $row, int $quantityBase): void
    {
        if ($quantityBase > $row->reserved) {
            throw new RuntimeException("SKU {$row->sku_id} has {$row->reserved} reserved, cannot take back {$quantityBase}.");
        }
    }
}
