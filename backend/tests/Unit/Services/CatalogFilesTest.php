<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->files = app(CatalogFiles::class);
});

test('an upload is kept under the tenant with its original name and format', function (): void {
    $stored = $this->files->storeUpload(UploadedFile::fake()->createWithContent('قائمة الأضواء.csv', "sku_code\nA\n"));

    expect($stored['format'])->toBe(SpreadsheetFormat::Csv)
        ->and($stored['name'])->toBe('قائمة الأضواء.csv')
        ->and($stored['path'])->toBe(Storage::disk('local')->path("imports/{$this->tenant->id}/{$stored['token']}.csv"))
        ->and($this->files->upload($stored['token']))->toBe($stored);
});

test('a .txt upload is kept as a csv and an xlsx as an xlsx', function (string $name, SpreadsheetFormat $format): void {
    expect($this->files->storeUpload(UploadedFile::fake()->createWithContent($name, 'x'))['format'])->toBe($format);
})->with([
    'txt' => ['products.txt', SpreadsheetFormat::Csv],
    'xlsx' => ['products.xlsx', SpreadsheetFormat::Xlsx],
]);

test('an upload older than the token hours is gone for the import but not for a running job', function (): void {
    $token = $this->files->storeUpload(UploadedFile::fake()->createWithContent('a.csv', 'x'))['token'];

    $this->travel(25)->hours();

    expect($this->files->upload($token))->toBeNull()
        ->and($this->files->upload($token, evenExpired: true))->not->toBeNull();
});

test('an unknown token has no upload', function (): void {
    expect($this->files->upload('0b0b0b0b-0000-4000-8000-000000000000'))->toBeNull();
});

test('BR-T04 the uploads of another tenant are not found', function (): void {
    $token = $this->files->storeUpload(UploadedFile::fake()->createWithContent('a.csv', 'x'))['token'];

    expect($this->asTenant($this->otherTenant(), fn (): ?array => app(CatalogFiles::class)->upload($token)))->toBeNull();
});

test('deleting an upload removes the file and its name', function (): void {
    $token = $this->files->storeUpload(UploadedFile::fake()->createWithContent('a.csv', 'x'))['token'];

    $this->files->deleteUpload($token);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('storing an upload prunes the tenant\'s expired ones', function (): void {
    $old = $this->files->storeUpload(UploadedFile::fake()->createWithContent('a.csv', 'x'))['token'];
    $this->travel(25)->hours();

    $this->files->storeUpload(UploadedFile::fake()->createWithContent('b.csv', 'x'));

    expect($this->files->upload($old, evenExpired: true))->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

test('an export is pending while written, then its file, or failed', function (): void {
    $path = $this->files->newExport('e1', SpreadsheetFormat::Xlsx);
    file_put_contents($path, 'xlsx');

    expect($this->files->export('e1'))->toBe('pending')
        ->and($path)->toEndWith('e1.xlsx.part');

    $this->files->finishExport('e1', SpreadsheetFormat::Xlsx);

    expect($this->files->export('e1'))->toBe(['path' => Storage::disk('local')->path("exports/{$this->tenant->id}/e1.xlsx"), 'format' => SpreadsheetFormat::Xlsx]);

    $this->files->markExportFailed('e2');

    expect($this->files->export('e2'))->toBe('failed');
});

test('an export older than the token hours is pruned when the next one starts', function (): void {
    file_put_contents($this->files->newExport('old', SpreadsheetFormat::Csv), 'csv');
    $this->files->finishExport('old', SpreadsheetFormat::Csv);
    $this->travel(25)->hours();

    expect($this->files->export('old'))->toBe('pending');

    $this->files->newExport('new', SpreadsheetFormat::Csv);

    expect(Storage::disk('local')->exists("exports/{$this->tenant->id}/old.csv"))->toBeFalse();
});
