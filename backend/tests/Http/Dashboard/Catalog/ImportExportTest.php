<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Jobs\ExportCatalog;
use App\Modules\Catalog\Jobs\ImportCatalog;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Tenancy\Enums\TenantUserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| V2 Task 06: GET products/export, GET products/export/{export}, GET products/import/template,
| POST products/import/preview, POST products/import, GET imports/{import}.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->importBasics();
});

test('the export streams the filtered catalog as an xlsx download', function (): void {
    $this->importedProduct();
    $this->inTenant(fn () => Product::factory()->withSku(['code' => 'OTHER-1'])->create());

    $response = $this->tenantJson('GET', '/api/dashboard/products/export?q=الأضواء');

    $response->assertOk()
        ->assertHeader('content-type', SpreadsheetFormat::Xlsx->mimeType())
        ->assertDownload('products-'.now()->format('Y-m-d').'.xlsx');
    expect(array_column(spreadsheetRows(downloadedFile($response)), 1))->toBe(['كود الصنف', 'sku_code', 'ADW-SCI-1']);
});

test('the export can be a csv with the keys only', function (): void {
    $this->importedProduct();

    $response = $this->tenantJson('GET', '/api/dashboard/products/export?format=csv');

    $response->assertOk()->assertDownload('products-'.now()->format('Y-m-d').'.csv');
    expect(array_column(spreadsheetRows(downloadedFile($response, SpreadsheetFormat::Csv), SpreadsheetFormat::Csv), 1))->toBe(['sku_code', 'ADW-SCI-1']);
});

test('D93 the xlsx export has the bundles on their own sheet, the csv leaves them out', function (): void {
    $this->importedBundle($this->importedProduct());

    $xlsx = downloadedFile($this->tenantJson('GET', '/api/dashboard/products/export')->assertOk());
    $csv = downloadedFile($this->tenantJson('GET', '/api/dashboard/products/export?format=csv')->assertOk(), SpreadsheetFormat::Csv);

    expect(array_column(spreadsheetRows($xlsx), 1))->toBe(['كود الصنف', 'sku_code', 'ADW-SCI-1'])
        ->and(array_column(spreadsheetRows($xlsx, sheetName: 'bundles_v1'), 0))->toBe(['كود الباقة', 'bundle_code', 'SET-1'])
        ->and(array_column(spreadsheetRows($csv, SpreadsheetFormat::Csv), 1))->toBe(['sku_code', 'ADW-SCI-1']);
});

test('the export validates its format and filter', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/dashboard/products/export?{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown format' => ['format=pdf', 'format'],
    'category not an id' => ['category=abc', 'category'],
    'unknown status' => ['status=deleted', 'status'],
]);

test('D95 a catalog above the sync limit is exported by a job behind a signed link', function (): void {
    config(['catalog.export_sync_limit' => 0]);
    $this->importedProduct();

    $response = $this->tenantJson('GET', '/api/dashboard/products/export?format=csv')->assertStatus(202);

    $id = $response->json('data.id');
    expect(Str::isUuid($id))->toBeTrue()
        ->and($response->json('data.download_url'))->toStartWith("/api/dashboard/products/export/{$id}?expires=");
    Queue::assertPushed(ExportCatalog::class, fn (ExportCatalog $job): bool => $job->uuid === $id && $job->subdomain === 'alnoor' && $job->format === SpreadsheetFormat::Csv);
});

test('the signed link answers not ready until the job wrote the file, then the file', function (): void {
    config(['catalog.export_sync_limit' => 0]);
    $this->importedProduct();
    $link = (string) $this->tenantJson('GET', '/api/dashboard/products/export')->json('data.download_url');

    $this->tenantJson('GET', $link)->assertConflict()->assertJsonPath('error.code', 'export_not_ready');

    Queue::pushed(ExportCatalog::class)->each(fn (ExportCatalog $job) => runJob($job));
    $file = $this->get('http://alnoor.gomal.test'.$link, ['Origin' => 'http://alnoor.gomal.test']);

    $file->assertOk()->assertDownload('products-'.now()->format('Y-m-d').'.xlsx');
    expect(spreadsheetRows($file->baseResponse->getFile()->getPathname())[3][1])->toBe('ADW-SCI-1');
});

test('a link with a wrong signature or a failed export is not found', function (): void {
    config(['catalog.export_sync_limit' => 0]);
    $this->importedProduct();
    $link = (string) $this->tenantJson('GET', '/api/dashboard/products/export')->json('data.download_url');
    $id = Str::between($link, '/export/', '?');

    $this->tenantJson('GET', $link.'0')->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->inTenant(fn () => app(CatalogFiles::class)->markExportFailed($id));

    $this->tenantJson('GET', $link)->assertNotFound()->assertJsonPath('error.message', 'رابط التحميل غير صالح أو انتهت صلاحيته، صدّر مرة أخرى');
});

test('BR-T04 a signed link of another tenant is not found here', function (): void {
    config(['catalog.export_sync_limit' => 0]);
    $other = $this->otherTenant();
    $theirs = $this->makeOwner($other);
    $this->inTenant(fn () => Product::factory()->withSku()->create(), $other);
    $this->actingAs($theirs, 'tenant_user');
    $link = (string) $this->tenantJson('GET', '/api/dashboard/products/export', tenant: $other)->json('data.download_url');
    Queue::pushed(ExportCatalog::class)->each(fn (ExportCatalog $job) => runJob($job));
    $this->actingAs($this->owner, 'tenant_user');

    $this->tenantJson('GET', $link)->assertConflict()->assertJsonPath('error.code', 'export_not_ready');
});

test('the template is the empty file with the store\'s custom field columns', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/products/import/template');

    $response->assertOk()->assertDownload('products-template-'.now()->format('Y-m-d').'.xlsx');
    $rows = spreadsheetRows(downloadedFile($response));
    expect($rows)->toHaveCount(2)
        ->and(array_slice($rows[2], -3))->toBe(['on_hand', 'field:publisher', 'field:brand'])
        ->and(array_slice($rows[1], -2))->toBe(['الشركة', 'الماركة']);
});

test('the preview answers the counts, the row errors and a token, and writes nothing', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/products/import/preview', ['file' => catalogUpload([
        $this->importRow(),
        $this->importRow(['sku_code' => 'B-1', 'unit' => 'علبة']),
    ])]);

    $response->assertOk()
        ->assertExactJsonStructure(['data' => ['token', 'file_name', 'total', 'create', 'update', 'skip', 'errors' => [['row', 'column', 'label', 'message']], 'errors_truncated']])
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.create', 1)
        ->assertJsonPath('data.skip', 1)
        ->assertJsonPath('data.errors.0', ['row' => 4, 'column' => 'unit', 'label' => 'وحدة البيع', 'message' => 'الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات'])
        ->assertJsonPath('data.errors_truncated', false);
    expect($this->inTenant(fn (): int => Product::query()->count()))->toBe(0);
});

test('the preview validates the upload', function (Closure $body): void {
    $this->tenantJson('POST', '/api/dashboard/products/import/preview', $body())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['file']]]);
})->with([
    'no file' => [fn (): array => []],
    'not a file' => [fn (): array => ['file' => 'products.xlsx']],
    'a pdf' => [fn (): array => ['file' => UploadedFile::fake()->create('products.pdf', 10, 'application/pdf')]],
    'an image' => [fn (): array => ['file' => UploadedFile::fake()->image('products.png')]],
    'too large' => [fn (): array => ['file' => UploadedFile::fake()->create('products.xlsx', 10241)]],
]);

test('a file that cannot be read is refused on the file field', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products/import/preview', ['file' => UploadedFile::fake()->createWithContent('products.xlsx', 'not a spreadsheet')])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.file.0', 'تعذّرت قراءة الملف، ارفع ملف Excel (xlsx) أو CSV');
});

test('the import of a previewed file is queued and answers the run', function (): void {
    $token = (string) $this->tenantJson('POST', '/api/dashboard/products/import/preview', ['file' => catalogUpload([$this->importRow()])])->assertOk()->json('data.token');

    $response = $this->tenantJson('POST', '/api/dashboard/products/import', ['token' => $token]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.file_name', 'products.xlsx')
        ->assertJsonPath('data.total', 0);
    expectDocumentedShape($response->json('data'), 'CatalogImport');
    Queue::assertPushed(ImportCatalog::class, fn (ImportCatalog $job): bool => $job->importId === $response->json('data.id'));
    $this->assertDatabaseHas('imports', ['tenant_id' => $this->tenant->id, 'uuid' => $token, 'created_by' => $this->owner->id, 'status' => 'queued']);
});

test('BR-L starting an import is audited as the user under the import\'s batch', function (): void {
    $token = (string) $this->tenantJson('POST', '/api/dashboard/products/import/preview', ['file' => catalogUpload([$this->importRow()])])->assertOk()->json('data.token');

    $id = $this->tenantJson('POST', '/api/dashboard/products/import', ['token' => $token])->json('data.id');

    $log = $this->inTenant(fn (): AuditLog => AuditLog::query()->where('action', 'catalog.import_started')->sole());
    expect($log->actor_id)->toBe($this->owner->id)
        ->and($log->actor_type->value)->toBe('tenant_user')
        ->and($log->entity_type)->toBe('imports')
        ->and($log->entity_id)->toBe($id)
        ->and($log->new_values)->toBe(['file_name' => 'products.xlsx'])
        ->and($log->batch_id)->toBe($this->inTenant(fn (): ?string => CatalogImport::query()->value('batch_id')));
});

test('a token is imported once: asking again answers the same run', function (): void {
    $token = (string) $this->tenantJson('POST', '/api/dashboard/products/import/preview', ['file' => catalogUpload([$this->importRow()])])->assertOk()->json('data.token');

    $first = $this->tenantJson('POST', '/api/dashboard/products/import', ['token' => $token])->json('data.id');
    $second = $this->tenantJson('POST', '/api/dashboard/products/import', ['token' => $token])->json('data.id');

    expect($second)->toBe($first);
    Queue::assertPushed(ImportCatalog::class, 1);
});

test('an expired or unknown token is refused', function (bool $travel, ?string $token): void {
    $token ??= (string) $this->tenantJson('POST', '/api/dashboard/products/import/preview', ['file' => catalogUpload([$this->importRow()])])->assertOk()->json('data.token');

    if ($travel) {
        $this->travel(25)->hours();
    }

    $this->tenantJson('POST', '/api/dashboard/products/import', ['token' => $token])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'import_expired')
        ->assertJsonPath('error.message', 'انتهت صلاحية الملف المرفوع، ارفعه مرة أخرى');
    Queue::assertNothingPushed();
})->with([
    'older than 24 hours' => [true, null],
    'never previewed' => [false, '7a1c6a4e-5a0e-4d8e-9c1e-2b9b3f0a1d11'],
]);

test('the import validates its token', function (array $body): void {
    $this->tenantJson('POST', '/api/dashboard/products/import', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['token']]]);
})->with([
    'missing' => [[]],
    'not a uuid' => [['token' => 'abc']],
]);

test('the progress of an import shows its counts and errors', function (): void {
    $import = $this->inTenant(fn (): CatalogImport => CatalogImport::factory()->done()->create([
        'total' => 2, 'processed' => 2, 'created' => 1, 'skipped' => 1,
        'errors' => [['row' => 4, 'column' => 'base_unit', 'label' => 'الوحدة الأساسية', 'message' => 'الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات']],
    ]));

    $response = $this->tenantJson('GET', "/api/dashboard/imports/{$import->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $import->id)
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.errors.0.column', 'base_unit');
    expectDocumentedShape($response->json('data'), 'CatalogImport');
});

test('BR-T04 the import of another tenant is not found and stays as it is', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): CatalogImport => CatalogImport::factory()->create(), $other);

    $this->tenantJson('GET', "/api/dashboard/imports/{$theirs->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('imports', ['id' => $theirs->id, 'tenant_id' => $other->id, 'status' => 'queued']);
});

test('D82 a role without catalog.manage cannot export or import', function (string $method, string $uri): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Sales]));

    $this->tenantJson($method, $uri)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
})->with([
    'export' => ['GET', '/api/dashboard/products/export'],
    'template' => ['GET', '/api/dashboard/products/import/template'],
    'preview' => ['POST', '/api/dashboard/products/import/preview'],
    'import' => ['POST', '/api/dashboard/products/import'],
]);

test('a dashboard session from one tenant host cannot start an import on another tenant host', function (): void {
    $other = $this->otherTenant();
    $this->app['auth']->forgetGuards();
    $this->makeOwner(attributes: ['email' => 'owner2@alnoor.test', 'password' => 'secret123']);
    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner2@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('POST', '/api/dashboard/products/import/preview', [], $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});
