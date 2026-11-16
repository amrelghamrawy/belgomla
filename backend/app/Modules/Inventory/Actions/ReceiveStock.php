<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Data\StockReceiptData;
use App\Modules\Inventory\Exceptions\CostingRefused;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Queries\SkuUnitFactorQuery;
use App\Modules\Inventory\Services\StockLedger;
use App\Support\UnitCost;
use Illuminate\Support\Facades\DB;

/**
 * BR-INV09, D96 b: the merchant receives goods they bought. The quantity in the unit named becomes base units
 * (BR-U03), the price per that unit a cost per base unit; the ledger writes the `receive` movement and moves the
 * average under the row lock. Audited `stock.received` with On Hand and the average before and after. A bundle has no
 * stock (`409 is_bundle`), a unit of another SKU is a validation error. Owns its transaction.
 */
final readonly class ReceiveStock
{
    public function __construct(
        private StockLedger $ledger,
        private SkuUnitFactorQuery $factors,
        private AuditLogger $audit,
    ) {}

    public function handle(Sku $sku, StockReceiptData $data, ?int $actorId = null): void
    {
        if ($sku->loadMissing('product')->product->isBundle()) {
            throw CostingRefused::isBundle();
        }

        $factor = $this->factors->get($sku, $data->skuUnitId);
        $quantityBase = $data->quantity * $factor;

        if ($quantityBase > AdjustStockRequest::MAX_ON_HAND) {
            throw CostingRefused::tooMuch(AdjustStockRequest::MAX_ON_HAND);
        }

        $unitCost = UnitCost::perBaseUnit($data->unitCost, $factor);

        DB::transaction(function () use ($sku, $data, $quantityBase, $unitCost, $actorId): void {
            $before = $this->ledger->lock([$sku->id])->get($sku->id);
            $old = ['on_hand' => $before->on_hand ?? 0, 'avg_cost' => $before?->avg_cost];

            $movement = $this->ledger->receive($sku, $quantityBase, $unitCost, $data->note, $actorId);
            $after = $this->ledger->lock([$sku->id])->get($sku->id);

            $this->audit->log('stock.received', $sku, $old, [
                'on_hand' => $movement->on_hand_after,
                'avg_cost' => $after?->avg_cost,
                'quantity' => $data->quantity,
                'sku_unit_id' => $data->skuUnitId,
                'quantity_base' => $quantityBase,
                'unit_cost' => $data->unitCost,
                'note' => $data->note,
            ]);
        });
    }
}
