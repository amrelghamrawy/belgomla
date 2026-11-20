<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\PreviewCatalogImport;
use App\Modules\Catalog\Actions\RunCatalogImport;
use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Exceptions\ImportUnavailable;
use App\Modules\Catalog\Jobs\ImportCatalog;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    app(TenantContext::class)->set($this->tenant);
    $this->token = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow()]))->token;
});

test('starting an import creates the queued run with a fresh batch and queues its job', function (): void {
    $owner = $this->makeOwner();

    $import = app(RunCatalogImport::class)->handle($this->token, $owner->id);

    expect($import->only(['uuid', 'file_name', 'created_by']))->toBe(['uuid' => $this->token, 'file_name' => 'products.xlsx', 'created_by' => $owner->id])
        ->and($import->status)->toBe(ImportStatus::Queued)
        ->and(AuditLog::query()->sole()->only(['action', 'entity_id', 'batch_id']))->toBe(['action' => 'catalog.import_started', 'entity_id' => $import->id, 'batch_id' => $import->batch_id]);
    Queue::assertPushed(ImportCatalog::class, fn (ImportCatalog $job): bool => $job->importId === $import->id);
});

test('the same token answers the same run without a second job', function (): void {
    $first = app(RunCatalogImport::class)->handle($this->token);

    $second = app(RunCatalogImport::class)->handle($this->token);

    expect($second->id)->toBe($first->id)
        ->and(CatalogImport::query()->count())->toBe(1);
    Queue::assertPushed(ImportCatalog::class, 1);
});

test('an expired token is refused and nothing is created', function (): void {
    $this->travel(25)->hours();

    expect(fn () => app(RunCatalogImport::class)->handle($this->token))->toThrow(ImportUnavailable::class);
    expect(CatalogImport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
