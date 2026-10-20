<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\PreviewCatalogImport;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->importBasics();
});

test('the preview counts the rows that create and the rows that update a product', function (): void {
    $this->importedProduct();

    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([
        $this->importRow(),
        $this->importRow(['sku_code' => 'ADW-SCI-2', 'product_name' => 'الأضواء علوم تانية إعدادي']),
        $this->importRow(['sku_code' => 'ADW-SCI-3', 'product_name' => 'الأضواء علوم تالتة إعدادي']),
    ]));

    expect($preview->total)->toBe(3)
        ->and($preview->update)->toBe(1)
        ->and($preview->create)->toBe(2)
        ->and($preview->skip)->toBe(0)
        ->and($preview->errors)->toBe([])
        ->and($preview->errorsTruncated)->toBeFalse()
        ->and($preview->fileName)->toBe('products.xlsx');
});

test('the preview writes nothing: no product, price, stock, movement or audit record', function (): void {
    $product = $this->importedProduct();
    $before = [Product::query()->count(), Sku::query()->count(), SkuPriceTier::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()];

    app(PreviewCatalogImport::class)->handle(catalogUpload([
        $this->importRow(['base_price' => '80', 'on_hand' => '100', 'status' => 'غير نشط']),
        $this->importRow(['sku_code' => 'NEW-1']),
    ]));

    expect([Product::query()->count(), Sku::query()->count(), SkuPriceTier::query()->count(), InventoryMovement::query()->count(), AuditLog::query()->count()])->toBe($before)
        ->and($product->sku?->refresh()->base_price)->toBe('90.00')
        ->and(SkuInventory::query()->whereKey($product->sku?->id)->value('on_hand'))->toBe(400)
        ->and($product->refresh()->status->value)->toBe('active');
});

test('the preview keeps the file for the import under its token', function (): void {
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow()], SpreadsheetFormat::Csv));

    expect(app(CatalogFiles::class)->upload($preview->token))->toMatchArray(['token' => $preview->token, 'format' => SpreadsheetFormat::Csv, 'name' => 'products.csv'])
        ->and(Storage::disk('local')->exists("imports/{$this->tenant->id}/{$preview->token}.csv"))->toBeTrue();
});

test('the preview lists every row error with its row number and column', function (array $row, string $column, string $message): void {
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow(['sku_code' => 'OK-1']), $row]));

    expect($preview->skip)->toBe(1)
        ->and($preview->create)->toBe(1)
        ->and($preview->errors)->toContain(['row' => 4, 'column' => $column, 'label' => $preview->errors[0]['label'], 'message' => $message])
        ->and($preview->errors[0]['row'])->toBe(4)
        ->and($preview->errors[0]['column'])->toBe($column);
})->with([
    'missing code' => [fn () => $this->importRow(['sku_code' => '']), 'sku_code', 'كود الصنف مطلوب.'],
    'BR-PR01 base price not above zero' => [fn () => $this->importRow(['base_price' => '0']), 'base_price', 'يجب أن تكون قيمة سعر الجملة أكبر من 0.'],
    'D28 a tier above the base price' => [fn () => $this->importRow(['tier_1_price' => '95']), 'tiers', 'سعر شريحة 10 أعلى من السعر الأساسي'],
    'BR-U01 unknown unit' => [fn () => $this->importRow(['base_unit' => 'علبة']), 'base_unit', 'الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات'],
    'D25 missing category' => [fn () => $this->importRow(['category_path' => '']), 'category_path', 'التصنيف مطلوب.'],
    'D25 unknown category' => [fn () => $this->importRow(['category_path' => 'كتب خارجية > تانية ثانوي']), 'category_path', 'التصنيف غير موجود، أنشئه أولًا من صفحة التصنيفات'],
    'bad number' => [fn () => $this->importRow(['moq' => 'خمسة']), 'moq', 'يجب أن يكون الحد الأدنى للطلب عددًا صحيحًا.'],
    'BR-PR10 a percent without a reference price' => [fn () => $this->importRow(['cover_price' => '', 'base_price' => '', 'base_price_percent' => '25']), 'cover_price', 'أدخل سعر الغلاف أولًا لحساب السعر بنسبة الخصم'],
]);

test('BR-P03 a code that appears twice in the file is an error on the second row', function (): void {
    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow(), $this->importRow(['product_name' => 'نسخة'])]));

    expect($preview->create)->toBe(1)
        ->and($preview->skip)->toBe(1)
        ->and($preview->errors)->toBe([['row' => 4, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'الكود مكرر في الملف (الصف 3)']]);
});

test('BR-INV08 an on hand below what open orders reserve is an error on the stock column', function (): void {
    $product = $this->importedProduct();
    SkuInventory::query()->whereKey($product->sku?->id)->update(['reserved' => 100]);

    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow(['on_hand' => '50'])]));

    expect($preview->errors[0]['column'])->toBe('on_hand')
        ->and($preview->errors[0]['message'])->toStartWith('لا يمكن تقليل المخزون إلى 50 لأن 100 وحدة محجوزة');
});

test('only the first errors are returned, flagged as cut', function (): void {
    config(['catalog.import_preview_errors' => 2]);

    $preview = app(PreviewCatalogImport::class)->handle(catalogUpload([
        $this->importRow(['sku_code' => 'A', 'base_unit' => 'علبة', 'selling_unit' => 'علبة']),
        $this->importRow(['sku_code' => 'B', 'base_unit' => 'علبة']),
    ]));

    expect($preview->errors)->toHaveCount(2)
        ->and($preview->errorsTruncated)->toBeTrue()
        ->and($preview->skip)->toBe(2);
});

test('a file without rows is refused as a whole and not kept', function (): void {
    expect(fn () => app(PreviewCatalogImport::class)->handle(catalogUpload([], columns: array_keys($this->importRow()))))
        ->toThrow(InvalidImportFile::class);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('a file of another format version is refused as a whole', function (): void {
    expect(fn () => app(PreviewCatalogImport::class)->handle(catalogUpload([$this->importRow()], sheetName: 'products_v2')))
        ->toThrow(function (InvalidImportFile $e): void {
            expect($e->details)->toBe(['file' => ['إصدار الملف «products_v2» غير مدعوم، نزّل قالبًا جديدًا']]);
        });
});
