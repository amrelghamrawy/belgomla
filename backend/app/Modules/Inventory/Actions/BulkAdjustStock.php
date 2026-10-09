<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Data\BulkStockAdjustmentData;
use App\Modules\Inventory\Exceptions\BelowReserved;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Several counted SKUs at once (08-api-contract.md §7). Each SKU is adjusted like AdjustStock; a SKU that
 * is not the tenant's (`not_found`, BR-T04), would go below Reserved (`below_reserved`, BR-INV08) or is
 * already at that value (`unchanged`, D35) is skipped and reported, the rest are applied.
 *
 * One transaction (owned here) that locks every row up front in `sku_id` order, one audit batch: the
 * `stock.adjusted` records plus one `inventory.bulk_adjusted` summary.
 *
 * @phpstan-type Skipped array{sku_id: int, reason: 'not_found'|'below_reserved'|'unchanged', reserved?: int, min_allowed?: int}
 */
final readonly class BulkAdjustStock
{
    public function __construct(
        private StockLedger $ledger,
        private AdjustStock $adjust,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{applied: int, skipped: list<Skipped>, batch_id: string}
     */
    public function handle(BulkStockAdjustmentData $data, ?int $actorId = null): array
    {
        $batchId = $this->audit->newBatchId();

        return DB::transaction(function () use ($data, $actorId, $batchId): array {
            $skus = Sku::query()->whereKey(array_keys($data->items))->get()->keyBy('id');
            $this->ledger->lock(array_values(array_map(fn (Sku $sku): int => $sku->id, $skus->all())));

            $applied = 0;
            $skipped = [];

            foreach ($data->items as $skuId => $item) {
                $sku = $skus->get($skuId);

                if (! $sku instanceof Sku) {
                    $skipped[] = ['sku_id' => $skuId, 'reason' => 'not_found'];

                    continue;
                }

                try {
                    $changed = $this->adjust->handle($sku, $item, $actorId, $batchId);
                } catch (BelowReserved $e) {
                    $skipped[] = ['sku_id' => $skuId, 'reason' => 'below_reserved', 'reserved' => $e->reserved(), 'min_allowed' => $e->reserved()];

                    continue;
                }

                if (! $changed) {
                    $skipped[] = ['sku_id' => $skuId, 'reason' => 'unchanged'];

                    continue;
                }

                $applied++;
            }

            $this->audit->log('inventory.bulk_adjusted', 'sku_inventory', null, ['applied' => $applied, 'skipped' => count($skipped)], $batchId);

            return ['applied' => $applied, 'skipped' => $skipped, 'batch_id' => $batchId];
        });
    }
}
