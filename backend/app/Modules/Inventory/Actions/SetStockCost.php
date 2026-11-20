<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Exceptions\CostingRefused;
use App\Modules\Inventory\Queries\SkuUnitFactorQuery;
use App\Modules\Inventory\Services\StockLedger;
use App\Support\UnitCost;
use Illuminate\Support\Facades\DB;

/**
 * D96 d: the merchant sets a SKU's average cost by hand, for stock that was there before costing or to correct a
 * figure. `unitCost` is money per the unit named (null = the base unit), stored per base unit. No movement (On Hand
 * does not change); audited `stock.cost_set` with the old and new average, nothing when it is unchanged. A bundle has no
 * stock (`409 is_bundle`). Owns its transaction.
 */
final readonly class SetStockCost
{
    public function __construct(
        private StockLedger $ledger,
        private SkuUnitFactorQuery $factors,
        private AuditLogger $audit,
    ) {}

    public function handle(Sku $sku, string $unitCost, ?int $skuUnitId = null): void
    {
        if ($sku->loadMissing('product')->product->isBundle()) {
            throw CostingRefused::isBundle();
        }

        $cost = UnitCost::perBaseUnit($unitCost, $this->factors->get($sku, $skuUnitId));

        DB::transaction(function () use ($sku, $cost, $unitCost, $skuUnitId): void {
            $old = $this->ledger->setCost($sku, $cost);

            if ($old === $cost) {
                return;
            }

            $this->audit->log('stock.cost_set', $sku, ['avg_cost' => $old], ['avg_cost' => $cost, 'unit_cost' => $unitCost, 'sku_unit_id' => $skuUnitId]);
        });
    }
}
