<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\CatalogLookups;
use App\Modules\Catalog\Data\ImportRowData;
use App\Modules\Catalog\Data\ImportRowResult;
use App\Modules\Catalog\Enums\ImportRowAction;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Exceptions\InvalidImportRow;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Services\BundleSheet;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Support\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One product of a catalog file (V2 Task 06; its SKUs' rows, one per unit, since V2 Task 13; its variants since V2
 * Task 14), in its own transaction: when none of its SKU codes is the tenant's it is created through CreateProduct (each
 * SKU's `on_hand` becomes its opening stock); otherwise the product those codes belong to is updated through
 * UpdateProduct (which prices through UpdateSkuPricing and keeps the SKUs the file does not list), then moved to the
 * file's status (SetProductStatus, BR-P04), and each known SKU's On Hand is set through Inventory's AdjustStock with the
 * reason «استيراد» (BR-INV08; the same value is a no-op, D35). Every audit record carries the import's batch id.
 *
 * A product that fails anything is rolled back whole and comes back as `skip` with its errors per row and column; so
 * is a product the file repeats (`duplicates`) and one whose codes belong to two products of the store. A database
 * failure is reported and marked `unexpected`. Codes are looked up in the tenant in context only, so another tenant's
 * code is a new product here (BR-P03). A row with a bundle's code is an error, never an update: bundles are exported,
 * not imported (D93). The preview runs the same products inside a transaction it rolls back.
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
     * @param  non-empty-array<int, array<string, string>>  $rows  the product's rows, row number => row by column key (CatalogRowMapper::products)
     * @param  array<int, array<string, list<string>>>  $duplicates  row number => column => messages: what the file already had (CatalogRowMapper::products)
     */
    public function handle(array $rows, CatalogLookups $lookups, array $duplicates = [], ?string $batchId = null, ?int $actorId = null): ImportRowResult
    {
        $number = array_key_first($rows);
        $data = null;
        $product = null;

        try {
            if ($duplicates !== []) {
                throw InvalidImportRow::inRows($duplicates);
            }

            $product = $this->existing($rows);
            $data = $this->mapper->map($rows, $lookups, $product);

            DB::transaction(fn (): Product => $product instanceof Product
                ? $this->updateRow($product, $data, $batchId, $actorId)
                : $this->create->handle($data->product, $actorId, $batchId));
        } catch (ApiException $e) {
            return new ImportRowResult($number, ImportRowAction::Skip, $this->mapper->errors($e, $lookups, $data->rows ?? [[$number]]));
        } catch (QueryException $e) {
            report($e);

            return new ImportRowResult(
                $number,
                ImportRowAction::Skip,
                [['column' => 'row', 'label' => $this->mapper->label('row', $lookups), 'message' => 'تعذّر حفظ هذا الصف، حاول مرة أخرى']],
                unexpected: true,
            );
        }

        return new ImportRowResult($number, $product instanceof Product ? ImportRowAction::Update : ImportRowAction::Create);
    }

    /**
     * The product the rows' codes belong to, null when none is the tenant's. Codes of two products are an error on the
     * rows of the codes that are not the first one's product; a bundle's code is an error on its row (D93).
     *
     * @param  non-empty-array<int, array<string, string>>  $rows
     *
     * @throws InvalidImportRow
     */
    private function existing(array $rows): ?Product
    {
        $codes = [];

        foreach ($this->mapper->skus($rows) as $number => $skuRows) {
            $code = $skuRows[$number]['sku_code'] ?? '';

            if ($code !== '') {
                $codes[] = [$code, $number];
            }
        }

        if ($codes === []) {
            return null;
        }

        $owners = Sku::query()->whereIn('code', array_column($codes, 0))->get(['code', 'product_id']);
        $bundles = Product::query()->whereKey($owners->pluck('product_id')->all())->where('type', ProductType::Bundle)->pluck('id')->all();
        $productId = null;
        $errors = [];

        foreach ($codes as [$code, $number]) {
            $owner = $owners->first(fn (Sku $sku): bool => $sku->code === $code)?->product_id;

            if ($owner === null) {
                continue;
            }

            if (in_array($owner, $bundles, true)) {
                $errors[$number]['sku_code'] = [BundleSheet::NOT_IMPORTED];

                continue;
            }

            $productId ??= $owner;

            if ($owner !== $productId) {
                $errors[$number]['sku_code'] = ['هذا الكود لصنف من منتج آخر في المتجر'];
            }
        }

        if ($errors !== []) {
            throw InvalidImportRow::inRows($errors);
        }

        return $productId === null ? null : Product::query()
            ->with(['category', 'options.values', 'skus.activeUnits.tiers', 'skus.optionValues'])
            ->find($productId);
    }

    private function updateRow(Product $product, ImportRowData $data, ?string $batchId, ?int $actorId): Product
    {
        /** @var Collection<int, Sku> $stored */
        $stored = $product->skus;
        $product = $this->update->handle($product, $data->product, $batchId);

        if ($data->status instanceof ProductStatus && $data->status !== $product->status) {
            $this->status->handle($product, $data->status, $batchId);
        }

        foreach ($data->onHand as $index => $onHand) {
            $code = isset($data->product->skus[$index]) ? $data->product->skus[$index]->code : null;
            $sku = $stored->first(fn (Sku $sku): bool => $sku->code === $code);

            if ($sku instanceof Sku) {
                $this->adjust($sku, $onHand, $data->rows[$index][0] ?? null, $batchId, $actorId);
            }
        }

        return $product;
    }

    /** BR-INV08: a stock the SKU's reservations refuse is an error on that SKU's On Hand cell. */
    private function adjust(Sku $sku, int $onHand, ?int $row, ?string $batchId, ?int $actorId): void
    {
        try {
            $this->adjust->handle($sku, new StockAdjustmentData($onHand, self::STOCK_REASON), $actorId, $batchId);
        } catch (ApiException $e) {
            if ($row === null) {
                throw $e;
            }

            throw InvalidImportRow::inRows([$row => ['on_hand' => [$e->getMessage()]]]);
        }
    }
}
