<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Models\CatalogImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

test('to array', function (): void {
    $import = $this->inTenant(fn (): ?CatalogImport => CatalogImport::factory()->create()->fresh());

    expect(array_keys($import?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'uuid', 'file_name', 'status', 'total', 'processed', 'created', 'updated', 'skipped', 'failed', 'errors',
        'batch_id', 'created_by', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ]);
});

test('casts: the status enum, integer counts, the errors list and immutable dates', function (): void {
    $import = $this->inTenant(fn (): ?CatalogImport => CatalogImport::factory()->done()->create([
        'total' => '3', 'errors' => [['row' => 4, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'مطلوب']],
    ])->fresh());

    expect($import?->status)->toBe(ImportStatus::Done)
        ->and($import?->total)->toBe(3)
        ->and($import?->processed)->toBe(0)
        ->and($import?->errors)->toEqual([['row' => 4, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'مطلوب']])
        ->and($import?->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($import?->finished_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a new import is queued with no errors', function (): void {
    $import = $this->inTenant(fn (): ?CatalogImport => CatalogImport::factory()->create()->fresh());

    expect($import?->status)->toBe(ImportStatus::Queued)
        ->and($import?->errors)->toBe([]);
});

test('the database keeps counts non-negative and one run per token', function (array $attributes): void {
    $this->inTenant(fn () => CatalogImport::factory()->create(['uuid' => 'b8a1a6c2-6f3e-4a55-9f0e-0b8d8d2b2a10']));

    expect(fn () => $this->inTenant(fn (): CatalogImport => CatalogImport::factory()->create($attributes)))->toThrow(QueryException::class);
})->with([
    'negative count' => [['created' => -1]],
    'same token twice' => [['uuid' => 'b8a1a6c2-6f3e-4a55-9f0e-0b8d8d2b2a10']],
]);

test('every factory state is a valid run', function (string $state, ImportStatus $status): void {
    expect($this->inTenant(fn (): ImportStatus => CatalogImport::factory()->{$state}()->create()->status))->toBe($status);
})->with([
    'running' => ['running', ImportStatus::Running],
    'done' => ['done', ImportStatus::Done],
    'failed' => ['failed', ImportStatus::Failed],
]);

test('BR-T03 an import belongs to the tenant in context and is hidden from others', function (): void {
    $import = $this->inTenant(fn (): CatalogImport => CatalogImport::factory()->create());

    expect($import->tenant_id)->toBe($this->tenant->id)
        ->and($this->inTenant(fn (): int => CatalogImport::query()->count(), $this->otherTenant()))->toBe(0)
        ->and(fn () => CatalogImport::factory()->create())->toThrow(RuntimeException::class, 'without a tenant in context');
});

test('the database knows the statuses', function (): void {
    $import = $this->inTenant(fn (): CatalogImport => CatalogImport::factory()->create());

    expect(fn () => Illuminate\Support\Facades\DB::table('imports')->where('id', $import->id)->update(['status' => 'paused']))->toThrow(QueryException::class);
});
