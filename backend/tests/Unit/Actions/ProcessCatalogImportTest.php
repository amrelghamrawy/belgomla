<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ProcessCatalogImport;
use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->importBasics();
});

/** A queued run of a file stored as the preview stores it. */
function processImportOf(UploadedFile $file): CatalogImport
{
    return CatalogImport::factory()->create(['uuid' => app(CatalogFiles::class)->storeUpload($file)['token']]);
}

test('a run applies its rows and ends done; nothing is left to do', function (): void {
    $import = processImportOf(catalogUpload([$this->importRow(), $this->importRow(['sku_code' => 'B-1'])]));

    $more = app(ProcessCatalogImport::class)->handle($import);

    expect($more)->toBeFalse()
        ->and($import->refresh()->only(['status', 'total', 'processed', 'created']))->toBe(['status' => ImportStatus::Done, 'total' => 2, 'processed' => 2, 'created' => 2])
        ->and($import->started_at)->not->toBeNull();
});

test('a run stops after a chunk and says rows remain', function (): void {
    config(['catalog.import_chunk' => 1]);
    $import = processImportOf(catalogUpload([$this->importRow(), $this->importRow(['sku_code' => 'B-1'])]));

    expect(app(ProcessCatalogImport::class)->handle($import))->toBeTrue()
        ->and($import->refresh()->only(['status', 'processed']))->toBe(['status' => ImportStatus::Running, 'processed' => 1])
        ->and(app(ProcessCatalogImport::class)->handle($import))->toBeFalse()
        ->and($import->refresh()->only(['status', 'processed', 'created']))->toBe(['status' => ImportStatus::Done, 'processed' => 2, 'created' => 2]);
});

test('a file that cannot be read any more ends the run failed with its reason', function (): void {
    $import = processImportOf(UploadedFile::fake()->createWithContent('products.xlsx', 'not a spreadsheet'));

    expect(app(ProcessCatalogImport::class)->handle($import))->toBeFalse()
        ->and($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->errors[0]['message'])->toBe('تعذّرت قراءة الملف، ارفع ملف Excel (xlsx) أو CSV');
});

test('stored errors are capped', function (): void {
    config(['catalog.import_stored_errors' => 1]);
    $import = processImportOf(catalogUpload([$this->importRow(['sku_code' => '']), $this->importRow(['sku_code' => '', 'product_name' => ''])]));

    app(ProcessCatalogImport::class)->handle($import);

    expect($import->refresh()->skipped)->toBe(2)
        ->and($import->errors)->toHaveCount(1);
});
