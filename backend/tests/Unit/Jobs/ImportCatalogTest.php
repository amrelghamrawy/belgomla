<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\PreviewCatalogImport;
use App\Modules\Catalog\Actions\RunCatalogImport;
use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Jobs\ImportCatalog;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use RuntimeException as BaseRuntimeException;

beforeEach(function (): void {
    Queue::fake();
    app(TenantContext::class)->set($this->tenant);
    $this->importBasics();
    $this->owner = $this->makeOwner();
    $this->penRow = fn (string $code, string $color, array $overrides = []): array => $this->importRow([
        'product_key' => 'PEN', 'sku_code' => $code, 'product_name' => 'قلم جاف',
        'variant_option_1' => 'اللون', 'variant_value_1' => $color, 'variant_option_2' => 'المقاس', 'variant_value_2' => 'M', 'sku_status' => '',
        'unit' => 'قطعة', 'conversion_factor' => '1', 'cover_price' => '7', 'base_price' => '5', 'moq' => '10', 'tier_1_min' => '', 'tier_1_price' => '', 'on_hand' => '100',
        ...$overrides,
    ]);
});

/**
 * Previews the rows, starts the import as the owner and runs its job like a worker would.
 *
 * @param  list<array<string, string>>  $rows
 */
function importJobRun(Tests\TenantTestCase $test, array $rows, ?int $actorId = null): CatalogImport
{
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload($rows));
    $import = app(RunCatalogImport::class)->handle($preview->token, $actorId);

    runJob(new ImportCatalog($import->id));

    return $import->refresh();
}

test('a new code creates the product with its prices, tiers, fields and opening stock', function (): void {
    $import = importJobRun($this, [$this->importRow()], $this->owner->id);

    $product = Product::query()->with('skus.defaultUnit.tiers')->sole();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->only(['total', 'processed', 'created', 'updated', 'skipped', 'failed']))->toBe(['total' => 1, 'processed' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0])
        ->and($product->name)->toBe('الأضواء علوم أولى إعدادي')
        ->and($product->created_by)->toBe($this->owner->id)
        ->and($product->custom_fields)->toBe(['publisher' => $this->importBasics()['adwaa']->id])
        ->and($product->skus->first()?->code)->toBe('ADW-SCI-1')
        ->and($product->skus->first()?->defaultUnit?->only(['conversion_factor', 'cover_price', 'base_price', 'moq']))->toBe(['conversion_factor' => 20, 'cover_price' => '120.00', 'base_price' => '90.00', 'moq' => 5])
        ->and($product->skus->first()?->defaultUnit?->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([10 => '85.00'])
        ->and(InventoryMovement::query()->sole()->only(['type', 'quantity', 'actor_id']))->toEqual(['type' => App\Modules\Inventory\Enums\MovementType::Opening, 'quantity' => 400, 'actor_id' => $this->owner->id]);
});

test('a known code updates the product, its prices and its status', function (): void {
    $product = $this->importedProduct();

    $import = importJobRun($this, [$this->importRow(['product_name' => 'اسم جديد', 'base_price' => '88', 'tier_1_price' => '80', 'status' => 'غير نشط'])]);

    expect($import->updated)->toBe(1)
        ->and($product->refresh()->name)->toBe('اسم جديد')
        ->and($product->status)->toBe(ProductStatus::Inactive)
        ->and(SkuUnit::query()->sole()->base_price)->toBe('88.00')
        ->and(SkuUnit::query()->sole()->tiers()->pluck('unit_price')->all())->toBe(['80.00'])
        ->and(Product::query()->count())->toBe(1);
});

test('BR-INV06 the stock is adjusted to on_hand with the import reason', function (): void {
    $product = $this->importedProduct();

    importJobRun($this, [$this->importRow(['on_hand' => '350'])], $this->owner->id);

    $movement = InventoryMovement::query()->where('type', 'adjustment')->sole();

    expect(SkuInventory::query()->whereKey($product->skus->first()?->id)->value('on_hand'))->toBe(350)
        ->and($movement->only(['quantity', 'on_hand_after', 'reason', 'actor_id']))->toBe(['quantity' => -50, 'on_hand_after' => 350, 'reason' => 'استيراد', 'actor_id' => $this->owner->id]);
});

test('D35 an unchanged stock is no movement and no audit record', function (): void {
    $this->importedProduct();

    importJobRun($this, [$this->importRow()]);

    expect(InventoryMovement::query()->where('type', 'adjustment')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'stock.adjusted')->exists())->toBeFalse();
});

test('an empty on_hand leaves the stock alone', function (): void {
    $product = $this->importedProduct();

    importJobRun($this, [$this->importRow(['on_hand' => '0', 'sku_code' => 'NEW-1']), $this->importRow(['on_hand' => ''])]);

    expect(SkuInventory::query()->whereKey($product->skus->first()?->id)->value('on_hand'))->toBe(400);
});

test('one bad row is skipped with its error and does not roll back the others', function (): void {
    $import = importJobRun($this, [
        $this->importRow(['sku_code' => 'A-1']),
        $this->importRow(['sku_code' => 'A-2', 'tier_1_price' => '95']),
        $this->importRow(['sku_code' => 'A-3']),
    ]);

    expect($import->only(['created', 'skipped']))->toBe(['created' => 2, 'skipped' => 1])
        ->and(Sku::query()->orderBy('code')->pluck('code')->all())->toBe(['A-1', 'A-3'])
        ->and($import->errors)->toEqual([['row' => 4, 'column' => 'tiers', 'label' => 'شرائح السعر', 'message' => 'سعر شريحة 10 أعلى من السعر الأساسي']]);
});

test('a row that fails on stock is rolled back whole, product changes included', function (): void {
    $product = $this->importedProduct();
    SkuInventory::query()->whereKey($product->skus->first()?->id)->update(['reserved' => 100]);

    $import = importJobRun($this, [$this->importRow(['product_name' => 'لن يُحفظ', 'on_hand' => '50'])]);

    expect($import->skipped)->toBe(1)
        ->and($product->refresh()->name)->toBe('الأضواء علوم أولى إعدادي');
});

test('BR-L every change of the import is audited under one batch id, and an unchanged product gets no product.updated', function (): void {
    $this->importedProduct();

    $import = importJobRun($this, [
        $this->importRow(['base_price' => '88', 'on_hand' => '300']),
        $this->importRow(['sku_code' => 'NEW-1']),
    ], $this->owner->id);

    $logs = AuditLog::query()->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['catalog.import_started', 'sku.price_changed', 'stock.adjusted', 'product.created', 'catalog.imported'])
        ->and($logs->pluck('batch_id')->unique()->all())->toBe([$import->batch_id])
        ->and($logs->last()?->new_values)->toEqual(['total' => 2, 'created' => 1, 'updated' => 1, 'skipped' => 0, 'failed' => 0]);
});

test('BR-T04 the code of another tenant never matches: it is a new product here', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->asTenant($other, fn (): Product => Product::factory()->withSku(['code' => 'ADW-SCI-1', 'base_price' => '50.00'])->create(['name' => 'منتجهم']));

    $import = importJobRun($this, [$this->importRow()]);

    expect($import->created)->toBe(1)
        ->and(Product::query()->sole()->name)->toBe('الأضواء علوم أولى إعدادي')
        ->and($this->asTenant($other, fn (): ?string => $theirs->refresh()->name))->toBe('منتجهم')
        ->and($this->asTenant($other, fn (): ?string => SkuUnit::query()->sole()->base_price))->toBe('50.00');
});

test('the job applies one chunk of rows and queues itself for the rest', function (): void {
    config(['catalog.import_chunk' => 2]);
    $rows = [$this->importRow(['sku_code' => 'A-1']), $this->importRow(['sku_code' => 'A-2']), $this->importRow(['sku_code' => 'A-1'])];

    $import = importJobRun($this, $rows);

    expect($import->only(['status', 'total', 'processed', 'created']))->toBe(['status' => ImportStatus::Running, 'total' => 3, 'processed' => 2, 'created' => 2]);
    Queue::assertPushed(ImportCatalog::class, 2);

    runJob(new ImportCatalog($import->id));

    expect($import->refresh()->only(['status', 'processed', 'skipped']))->toBe(['status' => ImportStatus::Done, 'processed' => 3, 'skipped' => 1])
        ->and($import->errors[0]['message'])->toBe('الكود مكرر في الملف (الصف 3)');
});

test('the uploaded file is deleted once the import is done', function (): void {
    $import = importJobRun($this, [$this->importRow()]);

    expect(app(CatalogFiles::class)->upload($import->uuid, evenExpired: true))->toBeNull();
});

test('a finished import is not run again', function (): void {
    $import = importJobRun($this, [$this->importRow()]);

    runJob(new ImportCatalog($import->id));

    expect($import->refresh()->created)->toBe(1)
        ->and(AuditLog::query()->where('action', 'catalog.imported')->count())->toBe(1);
});

test('an import whose file is gone ends failed', function (): void {
    $import = CatalogImport::factory()->create();

    runJob(new ImportCatalog($import->id));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->errors)->toEqual([['row' => 0, 'column' => 'file', 'label' => 'الملف', 'message' => 'الملف المرفوع لم يعد موجودًا، ارفعه مرة أخرى']])
        ->and(AuditLog::query()->where('action', 'catalog.import_failed')->value('batch_id'))->toBe($import->batch_id);
});

test('a job that dies leaves the import failed', function (): void {
    $import = CatalogImport::factory()->running()->create();

    (new ImportCatalog($import->id))->failed(new BaseRuntimeException('worker died'));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->finished_at)->not->toBeNull();
});

test('a job for an unknown import does nothing', function (): void {
    runJob(new ImportCatalog(999_999));

    expect(CatalogImport::query()->count())->toBe(0);
});

test('the job runs in the import\'s own tenant whatever the context', function (): void {
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow()]));
    $import = app(RunCatalogImport::class)->handle($preview->token);
    app(TenantContext::class)->clear();

    runJob(new ImportCatalog($import->id));

    expect($this->inTenant(fn (): int => Product::query()->count()))->toBe(1)
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

test('the job is queued on the default queue and tried once', function (): void {
    $job = new ImportCatalog(1);

    expect($job->queue)->toBe('default')
        ->and($job->tries)->toBe(1);
});

test('a row may name any active category and unit of the store', function (): void {
    Category::factory()->childOf($this->importBasics()['root'])->create(['name' => 'تانية إعدادي']);
    Unit::factory()->create(['name' => 'علبة']);

    $import = importJobRun($this, [$this->importRow(['category_path' => 'كتب خارجية > تانية إعدادي', 'unit' => 'علبة'])]);

    expect($import->created)->toBe(1);
});

test('D91 a format v1 file still imports, each row a SKU in its one unit', function (): void {
    $row = $this->importRow(['unit' => null, 'unit_is_default' => null]);
    $row['selling_unit'] = 'كرتونة';
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$row], sheetName: 'products_v1'));
    $import = app(RunCatalogImport::class)->handle($preview->token);

    runJob(new ImportCatalog($import->id));

    $unit = SkuUnit::query()->with('unit')->sole();

    expect($import->refresh()->created)->toBe(1)
        ->and([$unit->unit->name, $unit->conversion_factor, $unit->base_price, $unit->is_default])->toBe(['كرتونة', 20, '90.00', true]);
});

test('D91 consecutive rows of a code import as one SKU sold in each unit, counted once', function (): void {
    $import = importJobRun($this, [
        $this->importRow(['unit_is_default' => '']),
        $this->importRow(['unit' => 'قطعة', 'unit_is_default' => 'نعم', 'conversion_factor' => '1', 'cover_price' => '7', 'base_price' => '5', 'moq' => '10', 'tier_1_min' => '50', 'tier_1_price' => '4.5']),
        $this->importRow(['sku_code' => 'NEW-1']),
    ]);

    $units = SkuUnit::query()->whereHas('sku', fn (Illuminate\Database\Eloquent\Builder $sku): Illuminate\Database\Eloquent\Builder => $sku->where('code', 'ADW-SCI-1'))->with(['unit', 'tiers'])->orderBy('sort_order')->get();

    expect($import->only(['total', 'processed', 'created', 'skipped']))->toBe(['total' => 2, 'processed' => 2, 'created' => 2, 'skipped' => 0])
        ->and($units->map(fn (SkuUnit $unit): array => [$unit->unit->name, $unit->conversion_factor, $unit->base_price, $unit->moq, $unit->is_default, $unit->tiers->pluck('unit_price', 'min_quantity')->all()])->all())
        ->toBe([
            ['كرتونة', 20, '90.00', 5, false, [10 => '85.00']],
            ['قطعة', 1, '5.00', 10, true, [50 => '4.50']],
        ]);
});

test('D91 an update keeps the units the file does not list', function (): void {
    $product = $this->importedProduct();
    $piece = $this->addUnit($product->skus->first(), ['unit_id' => $this->importBasics()['piece']->id, 'conversion_factor' => 1, 'base_price' => '5.00']);

    $import = importJobRun($this, [$this->importRow(['base_price' => '88'])]);

    expect($import->updated)->toBe(1)
        ->and(SkuUnit::query()->orderBy('id')->get()->map(fn (SkuUnit $unit): array => [$unit->id, $unit->base_price, $unit->status->value])->all())
        ->toBe([[$this->defaultUnitId($product->skus->first()), '88.00', 'active'], [$piece->id, '5.00', 'active']]);
});

test('D91 an error of a SKU\'s second unit is recorded on its own row', function (): void {
    $import = importJobRun($this, [
        $this->importRow(),
        $this->importRow(['unit' => 'قطعة', 'conversion_factor' => '1', 'base_price' => '5']),
    ]);

    expect($import->only(['total', 'skipped']))->toBe(['total' => 1, 'skipped' => 1])
        ->and($import->errors)->toEqual([['row' => 4, 'column' => 'unit_is_default', 'label' => 'الوحدة الافتراضية', 'message' => 'وحدة افتراضية واحدة فقط لكل صنف']])
        ->and(Product::query()->count())->toBe(0);
});

test('D91 a code repeated after another code is a duplicate, not another unit', function (): void {
    $import = importJobRun($this, [
        $this->importRow(),
        $this->importRow(['sku_code' => 'NEW-1']),
        $this->importRow(['unit' => 'قطعة', 'unit_is_default' => '', 'conversion_factor' => '1', 'base_price' => '5']),
    ]);

    expect($import->only(['total', 'created', 'skipped']))->toBe(['total' => 3, 'created' => 2, 'skipped' => 1])
        ->and($import->errors[0]['message'])->toBe('الكود مكرر في الملف (الصف 3)')
        ->and(SkuUnit::query()->count())->toBe(2);
});

test('D91 a format v2 file still imports, one product per SKU', function (): void {
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow(), $this->importRow(['sku_code' => 'NEW-1'])], sheetName: 'products_v2'));
    $import = app(RunCatalogImport::class)->handle($preview->token);

    runJob(new ImportCatalog($import->id));

    expect($import->refresh()->only(['total', 'created']))->toBe(['total' => 2, 'created' => 2])
        ->and(Product::query()->count())->toBe(2);
});

test('BR-P14 format v3 groups rows sharing a product key into one product with its variants', function (): void {
    $import = importJobRun($this, [
        ($this->penRow)('PEN-BLUE', 'أزرق'),
        ($this->penRow)('PEN-RED', 'أحمر', ['on_hand' => '50', 'product_name' => 'يتجاهل']),
        ($this->penRow)('PEN-RED', 'أحمر', ['product_key' => '', 'unit' => 'كرتونة', 'unit_is_default' => '', 'conversion_factor' => '12', 'base_price' => '55', 'variant_option_1' => '', 'variant_value_1' => '']),
        $this->importRow(['sku_code' => 'NEW-1']),
    ], $this->owner->id);

    $pen = Product::query()->with(['options.values', 'skus.units', 'skus.inventory'])->where('name', 'قلم جاف')->sole();

    expect($import->only(['total', 'processed', 'created', 'skipped']))->toBe(['total' => 2, 'processed' => 2, 'created' => 2, 'skipped' => 0])
        ->and($pen->options->map(fn (ProductOption $option): array => [$option->name, $option->values->pluck('value')->all()])->all())->toBe([['اللون', ['أزرق', 'أحمر']], ['المقاس', ['M']]])
        ->and($pen->skus->map(fn (Sku $sku): array => [$sku->code, $sku->name, $sku->status->value, $sku->units->count(), $sku->inventory?->on_hand])->all())->toBe([
            ['PEN-BLUE', 'أزرق / M', 'active', 1, 100],
            ['PEN-RED', 'أحمر / M', 'active', 2, 50],
        ])
        ->and(InventoryMovement::query()->where('type', 'opening')->pluck('quantity')->sort()->values()->all())->toBe([50, 100, 400]);
});

test('BR-P14 a v3 update of one variant keeps the product\'s other variants, options and stock', function (): void {
    importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), ($this->penRow)('PEN-RED', 'أحمر', ['on_hand' => '50'])]);

    $import = importJobRun($this, [($this->penRow)('PEN-RED', 'أحمر', ['product_key' => '', 'base_price' => '4.5', 'on_hand' => ''])]);

    $pen = Product::query()->with(['options.values', 'skus.defaultUnit', 'skus.inventory'])->sole();

    expect($import->only(['total', 'updated', 'skipped']))->toBe(['total' => 1, 'updated' => 1, 'skipped' => 0])
        ->and($pen->skus->map(fn (Sku $sku): array => [$sku->code, $sku->status->value, $sku->defaultUnit?->base_price, $sku->inventory?->on_hand])->all())->toBe([
            ['PEN-BLUE', 'active', '5.00', 100],
            ['PEN-RED', 'active', '4.50', 50],
        ])
        ->and($pen->options->map(fn (ProductOption $option): int => $option->values->count())->all())->toBe([2, 1]);
});

test('BR-P14 a row naming no option of a variant keeps its values, so a v2 file updates a variant', function (): void {
    importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), ($this->penRow)('PEN-RED', 'أحمر')]);
    $row = ($this->penRow)('PEN-RED', 'أحمر', ['base_price' => '4.5']);
    unset($row['product_key'], $row['variant_option_1'], $row['variant_value_1'], $row['variant_option_2'], $row['variant_value_2'], $row['sku_status']);
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$row], sheetName: 'products_v2'));
    $import = app(RunCatalogImport::class)->handle($preview->token);

    runJob(new ImportCatalog($import->id));

    expect($import->refresh()->updated)->toBe(1)
        ->and(Sku::query()->with('defaultUnit')->orderBy('sort_order')->get()->map(fn (Sku $sku): array => [$sku->code, $sku->name, $sku->defaultUnit?->base_price])->all())
        ->toBe([['PEN-BLUE', 'أزرق / M', '5.00'], ['PEN-RED', 'أحمر / M', '4.50']]);
});

test('BR-P14 a new variant in the file is added to the product with its opening stock', function (): void {
    importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق')]);

    $import = importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), ($this->penRow)('PEN-GREEN', 'أخضر', ['on_hand' => '30', 'sku_status' => 'غير نشط'])], $this->owner->id);

    $green = Sku::query()->with('inventory')->where('code', 'PEN-GREEN')->sole();

    expect($import->only(['updated', 'created']))->toBe(['updated' => 1, 'created' => 0])
        ->and(Product::query()->count())->toBe(1)
        ->and([$green->name, $green->status->value, $green->sort_order, $green->inventory?->on_hand])->toBe(['أخضر / M', 'inactive', 1, 30])
        ->and(InventoryMovement::query()->where('sku_id', $green->id)->sole()->only(['type', 'quantity']))->toEqual(['type' => App\Modules\Inventory\Enums\MovementType::Opening, 'quantity' => 30]);
});

test('BR-P14 an error of one variant names its own row and skips the whole product', function (): void {
    $import = importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), ($this->penRow)('PEN-RED', 'أحمر', ['base_price' => '0'])]);

    expect($import->only(['total', 'skipped']))->toBe(['total' => 1, 'skipped' => 1])
        ->and($import->errors)->toEqual([['row' => 4, 'column' => 'base_price', 'label' => 'سعر الجملة', 'message' => 'يجب أن تكون قيمة سعر الجملة أكبر من 0.']])
        ->and(Product::query()->count())->toBe(0);
});

test('BR-INV08 a stock below what one variant has reserved is an error on that variant\'s row', function (): void {
    importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), ($this->penRow)('PEN-RED', 'أحمر', ['on_hand' => '50'])]);
    SkuInventory::query()->whereKey(Sku::query()->where('code', 'PEN-RED')->value('id'))->update(['reserved' => 40]);

    $import = importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق', ['on_hand' => '90']), ($this->penRow)('PEN-RED', 'أحمر', ['on_hand' => '10'])]);

    expect($import->skipped)->toBe(1)
        ->and(array_map(fn (array $error): array => [$error['row'], $error['column']], $import->errors))->toBe([[4, 'on_hand']])
        ->and(SkuInventory::query()->pluck('on_hand')->sort()->values()->all())->toBe([50, 100]);
});

test('BR-P14 codes of two products of the store under one key are an error on the other product\'s code', function (): void {
    $this->importedProduct();
    importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق')]);

    $import = importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), ($this->penRow)('ADW-SCI-1', 'أحمر')]);

    expect($import->skipped)->toBe(1)
        ->and($import->errors)->toEqual([['row' => 4, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'هذا الكود لصنف من منتج آخر في المتجر']]);
});

test('BR-P14 a product key that comes back after another product is a duplicate', function (): void {
    $import = importJobRun($this, [($this->penRow)('PEN-BLUE', 'أزرق'), $this->importRow(['sku_code' => 'NEW-1']), ($this->penRow)('PEN-RED', 'أحمر')]);

    expect($import->only(['total', 'created', 'skipped']))->toBe(['total' => 3, 'created' => 2, 'skipped' => 1])
        ->and($import->errors)->toEqual([['row' => 5, 'column' => 'product_key', 'label' => 'مفتاح المنتج', 'message' => 'مفتاح المنتج مكرر في الملف (الصف 3)']]);
});
