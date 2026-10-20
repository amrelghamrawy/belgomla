<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\CatalogLookups;
use App\Modules\Catalog\Data\ImportRowData;
use App\Modules\Catalog\Data\ImportRowResult;
use App\Modules\Catalog\Enums\ImportRowAction;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Support\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One row of a catalog file (V2 Task 06), in its own transaction: a SKU code the tenant does not have creates the
 * product through CreateProduct (`on_hand` becomes its opening stock), a known code updates it through
 * UpdateProduct (which prices through UpdateSkuPricing), then moves it to the row's status (SetProductStatus, BR-P04)
 * and sets On Hand through Inventory's AdjustStock with the reason «استيراد» (BR-INV08; the same value is a no-op,
 * D35). Every audit record carries the import's batch id.
 *
 * A row that fails anything is rolled back alone and comes back as `skip` with its errors per column; a database
 * failure is reported and marked `unexpected`. The code is looked up in the tenant in context only, so another
 * tenant's code is a new product here (BR-P03). The preview runs the same rows inside a transaction it rolls back.
 */
final readonly class ImportCatalogRow
{
    public const string STOCK_REASON = 'استيراد';

    public function __construct(
        private CatalogRowMapper $mapper,
        private CreateProduct $create,
        private UpdateProduct $update,
        private SetProductStatus $status,
        private AdjustStock $adjust,
    ) {}

    /**
     * @param  array<string, string>  $row  the row by column key (CatalogRowMapper::rows)
     * @param  int|null  $duplicateOf  the earlier row of the file with the same SKU code
     */
    public function handle(array $row, int $number, CatalogLookups $lookups, ?int $duplicateOf = null, ?string $batchId = null, ?int $actorId = null): ImportRowResult
    {
        if ($duplicateOf !== null) {
            return $this->skipped($number, 'sku_code', "الكود مكرر في الملف (الصف {$duplicateOf})", $lookups);
        }

        $sku = $this->existing($row['sku_code'] ?? '');

        try {
            $data = $this->mapper->map($row, $lookups, $sku?->product);

            DB::transaction(fn (): Product => $sku instanceof Sku
                ? $this->updateRow($sku, $data, $batchId, $actorId)
                : $this->create->handle($data->product, $actorId, $batchId));
        } catch (ApiException $e) {
            return new ImportRowResult($number, ImportRowAction::Skip, $this->mapper->errors($e, $lookups));
        } catch (QueryException $e) {
            report($e);

            return $this->skipped($number, 'row', 'تعذّر حفظ هذا الصف، حاول مرة أخرى', $lookups, unexpected: true);
        }

        return new ImportRowResult($number, $sku instanceof Sku ? ImportRowAction::Update : ImportRowAction::Create);
    }

    private function existing(string $code): ?Sku
    {
        if ($code === '') {
            return null;
        }

        return Sku::query()->with(['product.category', 'product.sku.tiers', 'inventory'])->where('code', $code)->first();
    }

    private function updateRow(Sku $sku, ImportRowData $data, ?string $batchId, ?int $actorId): Product
    {
        $product = $this->update->handle($sku->product, $data->product, $batchId);

        if ($data->status instanceof ProductStatus && $data->status !== $product->status) {
            $this->status->handle($product, $data->status, $batchId);
        }

        if ($data->onHand !== null) {
            $this->adjust->handle($sku, new StockAdjustmentData($data->onHand, self::STOCK_REASON), $actorId, $batchId);
        }

        return $product;
    }

    private function skipped(int $number, string $column, string $message, CatalogLookups $lookups, bool $unexpected = false): ImportRowResult
    {
        return new ImportRowResult(
            $number,
            ImportRowAction::Skip,
            [['column' => $column, 'label' => $this->mapper->label($column, $lookups), 'message' => $message]],
            $unexpected,
        );
    }
}
