<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Jobs\ExportCatalog;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Tenancy\TenantContext;
use RuntimeException as BaseRuntimeException;

test('the job writes the export of its own tenant, whatever the context', function (): void {
    $this->importedProduct();
    $this->otherTenant();

    runJob(new ExportCatalog('alnoor', 'e1', new ProductFilterData, SpreadsheetFormat::Csv));

    $file = $this->inTenant(fn (): array|string => app(CatalogFiles::class)->export('e1'));
    expect(is_array($file) ? spreadsheetRows($file['path'], SpreadsheetFormat::Csv)[2][0] : $file)->toBe('ADW-SCI-1')
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

test('a failed export leaves its marker so the link stops waiting', function (): void {
    (new ExportCatalog('alnoor', 'e1', new ProductFilterData, SpreadsheetFormat::Xlsx))->failed(new BaseRuntimeException('disk full'));

    expect($this->inTenant(fn (): array|string => app(CatalogFiles::class)->export('e1')))->toBe('failed');
});

test('an export for a store that no longer exists does nothing', function (): void {
    runJob(new ExportCatalog('gone', 'e1', new ProductFilterData, SpreadsheetFormat::Xlsx));

    expect($this->inTenant(fn (): array|string => app(CatalogFiles::class)->export('e1')))->toBe('pending');
});
