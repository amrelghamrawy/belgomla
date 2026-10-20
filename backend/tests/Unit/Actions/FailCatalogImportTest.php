<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\FailCatalogImport;
use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('a failed run keeps its row errors, adds the file error and closes the batch', function (): void {
    $import = CatalogImport::factory()->running()->create(['errors' => [['row' => 3, 'column' => 'moq', 'label' => 'الحد الأدنى للطلب', 'message' => 'خطأ']]]);

    app(FailCatalogImport::class)->handle($import, 'توقف الاستيراد');

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->errors)->toEqual([
            ['row' => 3, 'column' => 'moq', 'label' => 'الحد الأدنى للطلب', 'message' => 'خطأ'],
            ['row' => 0, 'column' => 'file', 'label' => 'الملف', 'message' => 'توقف الاستيراد'],
        ])
        ->and(AuditLog::query()->sole()->only(['action', 'batch_id']))->toBe(['action' => 'catalog.import_failed', 'batch_id' => $import->batch_id]);
});

test('a finished run is left as it is', function (): void {
    $import = CatalogImport::factory()->done()->create();

    app(FailCatalogImport::class)->handle($import, 'متأخر');

    expect($import->refresh()->status)->toBe(ImportStatus::Done)
        ->and(AuditLog::query()->count())->toBe(0);
});
