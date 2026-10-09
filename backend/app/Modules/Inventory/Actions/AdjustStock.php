<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * A merchant sets a SKU's On Hand to a counted value: an `adjustment` movement (BR-INV06) and a
 * `stock.adjusted` audit record (BR-L). Below Reserved is refused with the holding orders (BR-INV08);
 * the current value changes nothing (D35). Returns whether On Hand changed. Opens a transaction, which
 * nests into the caller's (bulk adjust passes its batch id).
 */
final readonly class AdjustStock
{
    public function __construct(
        private StockLedger $ledger,
        private AuditLogger $audit,
    ) {}

    public function handle(Sku $sku, StockAdjustmentData $data, ?int $actorId = null, ?string $batchId = null): bool
    {
        return DB::transaction(function () use ($sku, $data, $actorId, $batchId): bool {
            $movement = $this->ledger->adjust($sku, $data->newOnHand, $data->reason, $actorId);

            if (! $movement instanceof InventoryMovement) {
                return false;
            }

            $this->audit->log(
                'stock.adjusted',
                $sku,
                ['on_hand' => $movement->on_hand_after - $movement->quantity],
                ['on_hand' => $movement->on_hand_after, 'reason' => $data->reason],
                $batchId,
            );

            return true;
        });
    }
}
