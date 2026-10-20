<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ImportCatalogRow;
use App\Modules\Catalog\Enums\ImportRowAction;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->importBasics();
    $this->lookups = app(CatalogRowMapper::class)->lookups();
});

test('a new code creates the product and reports create', function (): void {
    $result = app(ImportCatalogRow::class)->handle($this->importRow(), 3, $this->lookups);

    expect($result->action)->toBe(ImportRowAction::Create)
        ->and($result->row)->toBe(3)
        ->and($result->errors)->toBe([])
        ->and(Product::query()->sole()->status)->toBe(ProductStatus::Active);
});

test('a known code updates the product and reports update', function (): void {
    $product = $this->importedProduct();

    $result = app(ImportCatalogRow::class)->handle($this->importRow(['status' => 'غير نشط', 'on_hand' => '100']), 3, $this->lookups, batchId: 'b0b0b0b0-0000-4000-8000-000000000000');

    expect($result->action)->toBe(ImportRowAction::Update)
        ->and($product->refresh()->status)->toBe(ProductStatus::Inactive)
        ->and(InventoryMovement::query()->where('reason', ImportCatalogRow::STOCK_REASON)->value('quantity'))->toBe(-300);
});

test('BR-P04 a status the product cannot move to is an error on the status column', function (): void {
    $this->importedProduct();

    $result = app(ImportCatalogRow::class)->handle($this->importRow(['status' => 'قريبًا']), 3, $this->lookups);

    expect($result->action)->toBe(ImportRowAction::Skip)
        ->and($result->errors[0]['column'])->toBe('status');
});

test('a duplicate of an earlier row is skipped without touching the catalog', function (): void {
    $result = app(ImportCatalogRow::class)->handle($this->importRow(), 5, $this->lookups, duplicateOf: 3);

    expect($result->errors)->toBe([['column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'الكود مكرر في الملف (الصف 3)']])
        ->and(Product::query()->count())->toBe(0);
});

test('a row the database refuses is reported as unexpected and rolled back alone', function (): void {
    DB::statement('ALTER TABLE products ADD CONSTRAINT products_import_test CHECK (name <> \'مرفوض\')');

    $result = app(ImportCatalogRow::class)->handle($this->importRow(['product_name' => 'مرفوض']), 3, $this->lookups);

    expect($result->unexpected)->toBeTrue()
        ->and($result->action)->toBe(ImportRowAction::Skip)
        ->and($result->errors[0]['message'])->toBe('تعذّر حفظ هذا الصف، حاول مرة أخرى')
        ->and(Product::query()->count())->toBe(0);
});
