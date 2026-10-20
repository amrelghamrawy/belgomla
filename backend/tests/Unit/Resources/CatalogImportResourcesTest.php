<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ImportPreview;
use App\Modules\Catalog\Http\Resources\CatalogImportResource;
use App\Modules\Catalog\Http\Resources\ImportPreviewResource;
use App\Modules\Catalog\Http\Resources\QueuedExportResource;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the import preview resource has the token, the counts and the errors', function (): void {
    $error = ['row' => 3, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'كود الصنف مطلوب.'];

    $data = rendered(new ImportPreviewResource(new ImportPreview('6c1d1b8e-1a4b-4a8d-9a52-1f1f2a3b4c5d', 'products.xlsx', 3, 1, 1, 1, [$error], true)), dashboardRequest());

    expect($data)->toBe([
        'token' => '6c1d1b8e-1a4b-4a8d-9a52-1f1f2a3b4c5d',
        'file_name' => 'products.xlsx',
        'total' => 3,
        'create' => 1,
        'update' => 1,
        'skip' => 1,
        'errors' => [$error],
        'errors_truncated' => true,
    ]);
    expectDocumentedShape($data, 'ImportPreview');
});

test('the catalog import resource has the run, its progress and its batch', function (): void {
    $import = CatalogImport::factory()->running()->create(['total' => 10, 'processed' => 4, 'created' => 2, 'updated' => 1, 'skipped' => 1])->refresh();

    $data = rendered(new CatalogImportResource($import), dashboardRequest());

    expect(array_keys($data))->toBe(['id', 'file_name', 'status', 'total', 'processed', 'created', 'updated', 'skipped', 'failed', 'errors', 'batch_id', 'started_at', 'finished_at', 'created_at'])
        ->and($data['status'])->toBe('running')
        ->and($data['processed'])->toBe(4)
        ->and($data['batch_id'])->toBe($import->batch_id)
        ->and($data['finished_at'])->toBeNull()
        ->and($data['started_at'])->toBe($import->started_at?->toIso8601String());
    expectDocumentedShape($data, 'CatalogImport');
});

test('a queued export resource has its id and a relative signed link valid for the token hours', function (): void {
    $this->freezeTime();

    $data = rendered(new QueuedExportResource('6c1d1b8e-1a4b-4a8d-9a52-1f1f2a3b4c5d'), dashboardRequest());

    expect(array_keys($data))->toBe(['id', 'download_url'])
        ->and($data['download_url'])->toStartWith('/api/dashboard/products/export/6c1d1b8e-1a4b-4a8d-9a52-1f1f2a3b4c5d?expires='.now()->addHours(24)->getTimestamp())
        ->and(URL::hasValidSignature(Illuminate\Http\Request::create((string) $data['download_url']), absolute: false))->toBeTrue();
});
