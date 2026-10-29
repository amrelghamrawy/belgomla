<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\ImportUnavailable;
use App\Modules\Catalog\Jobs\ExportCatalog;
use App\Modules\Catalog\Services\BundleSheet;
use App\Modules\Catalog\Services\CatalogExporter;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Queue::fake();
    app(TenantContext::class)->set($this->tenant);
    $this->book = $this->importedProduct();
    $this->exporter = app(CatalogExporter::class);
});

test('D95 a selection within the sync limit streams, nothing is queued', function (): void {
    config(['catalog.export_sync_limit' => 1]);

    expect($this->exporter->queueIfLarge(new ProductFilterData, SpreadsheetFormat::Xlsx))->toBeNull();
    Queue::assertNothingPushed();
});

test('D95 a selection above the sync limit is queued for the job', function (): void {
    config(['catalog.export_sync_limit' => 0]);
    $filter = new ProductFilterData(q: 'علوم');

    $uuid = $this->exporter->queueIfLarge($filter, SpreadsheetFormat::Csv);

    expect(Str::isUuid((string) $uuid))->toBeTrue();
    Queue::assertPushed(ExportCatalog::class, fn (ExportCatalog $job): bool => $job->uuid === $uuid && $job->filter === $filter && $job->format === SpreadsheetFormat::Csv && $job->queue === 'default');
});

test('the template has the header rows and no product', function (): void {
    $path = sys_get_temp_dir().'/template-'.Str::uuid().'.csv';

    $this->exporter->template($path, SpreadsheetFormat::Csv);

    expect(spreadsheetRows($path, SpreadsheetFormat::Csv))->toHaveCount(1)
        ->and(array_slice(spreadsheetRows($path, SpreadsheetFormat::Csv)[1], 0, 2))->toBe(['product_key', 'sku_code']);
});

test('a stored export is the finished file of the tenant', function (): void {
    $this->exporter->store('e1', new ProductFilterData, SpreadsheetFormat::Xlsx);

    $file = $this->exporter->download('e1', validSignature: true);

    expect($file['format'])->toBe(SpreadsheetFormat::Xlsx)
        ->and(spreadsheetRows($file['path'])[3][1])->toBe('ADW-SCI-1');
});

test('a download is refused while pending, when failed and without a valid signature', function (string $uuid, bool $signed, string $code): void {
    app(CatalogFiles::class)->markExportFailed('failed-one');

    expect(fn () => $this->exporter->download($uuid, $signed))->toThrow(function (ImportUnavailable $e) use ($code): void {
        expect($e->errorCode)->toBe($code);
    });
})->with([
    'pending' => ['pending-one', true, 'export_not_ready'],
    'failed' => ['failed-one', true, 'not_found'],
    'bad signature' => ['pending-one', false, 'not_found'],
]);

test('D93 an xlsx export writes the bundles to the bundles_v1 sheet, labels over keys, one row per component', function (): void {
    $this->importedBundle($this->book);
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.xlsx';

    $this->exporter->write($path, new ProductFilterData, SpreadsheetFormat::Xlsx);

    expect(spreadsheetRows($path, sheetName: 'bundles_v1'))->toBe([
        1 => array_values(BundleSheet::LABELS),
        2 => array_keys(BundleSheet::LABELS),
        3 => ['SET-1', 'باقة أولى إعدادي', 'نشط', 'كتب خارجية > أولى إعدادي', 'قطعة', '2', 'خصم من مجموع الأصناف', '10', '162', 'ADW-SCI-1', 'كرتونة', '2'],
    ]);
});

test('D93 the products sheet of an export leaves bundles out', function (SpreadsheetFormat $format): void {
    $this->importedBundle($this->book);
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.'.$format->value;

    $this->exporter->write($path, new ProductFilterData, $format);

    expect(array_values(array_filter(array_column(spreadsheetRows($path, $format), 1), fn (string $code): bool => $code !== 'sku_code' && $code !== 'كود الصنف')))->toBe(['ADW-SCI-1']);
})->with(['xlsx' => [SpreadsheetFormat::Xlsx], 'csv' => [SpreadsheetFormat::Csv]]);

test('D93 a csv export has no bundles at all: one sheet only', function (): void {
    $this->importedBundle($this->book);
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.csv';

    $this->exporter->write($path, new ProductFilterData, SpreadsheetFormat::Csv);

    expect((string) file_get_contents($path))->not->toContain('SET-1')
        ->and(spreadsheetRows($path, SpreadsheetFormat::Csv))->toHaveCount(2);
});

test('the template has no bundles sheet', function (): void {
    $this->importedBundle($this->book);
    $path = sys_get_temp_dir().'/template-'.Str::uuid().'.xlsx';

    $this->exporter->template($path, SpreadsheetFormat::Xlsx);

    expect(spreadsheetRows($path, sheetName: 'bundles_v1'))->toBe([]);
});

test('a file is named after what it holds and today', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 20));

    expect($this->exporter->fileName('products', SpreadsheetFormat::Csv))->toBe('products-2026-10-20.csv');
});
