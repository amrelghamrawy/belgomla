<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ImportCatalogRow;
use App\Modules\Catalog\Enums\ImportRowAction;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
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
    $result = app(ImportCatalogRow::class)->handle([3 => $this->importRow()], $this->lookups);

    expect($result->action)->toBe(ImportRowAction::Create)
        ->and($result->row)->toBe(3)
        ->and($result->errors)->toBe([])
        ->and(Product::query()->sole()->status)->toBe(ProductStatus::Active);
});

test('a known code updates the product and reports update', function (): void {
    $product = $this->importedProduct();

    $result = app(ImportCatalogRow::class)->handle([3 => $this->importRow(['status' => 'غير نشط', 'on_hand' => '100'])], $this->lookups, batchId: 'b0b0b0b0-0000-4000-8000-000000000000');

    expect($result->action)->toBe(ImportRowAction::Update)
        ->and($product->refresh()->status)->toBe(ProductStatus::Inactive)
        ->and(InventoryMovement::query()->where('reason', ImportCatalogRow::STOCK_REASON)->value('quantity'))->toBe(-300);
});

test('BR-P04 a status the product cannot move to is an error on the status column', function (): void {
    $this->importedProduct();

    $result = app(ImportCatalogRow::class)->handle([3 => $this->importRow(['status' => 'قريبًا'])], $this->lookups);

    expect($result->action)->toBe(ImportRowAction::Skip)
        ->and($result->errors[0]['column'])->toBe('status');
});

test('a duplicate of an earlier row is skipped without touching the catalog', function (): void {
    $result = app(ImportCatalogRow::class)->handle([5 => $this->importRow()], $this->lookups, duplicateOf: 3);

    expect($result->errors)->toBe([['column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'الكود مكرر في الملف (الصف 3)']])
        ->and(Product::query()->count())->toBe(0);
});

test('a row the database refuses is reported as unexpected and rolled back alone', function (): void {
    DB::statement('ALTER TABLE products ADD CONSTRAINT products_import_test CHECK (name <> \'مرفوض\')');

    $result = app(ImportCatalogRow::class)->handle([3 => $this->importRow(['product_name' => 'مرفوض'])], $this->lookups);

    expect($result->unexpected)->toBeTrue()
        ->and($result->action)->toBe(ImportRowAction::Skip)
        ->and($result->errors[0]['message'])->toBe('تعذّر حفظ هذا الصف، حاول مرة أخرى')
        ->and(Product::query()->count())->toBe(0);
});

test('D91 the rows of one code create a SKU sold in each of their units', function (): void {
    $result = app(ImportCatalogRow::class)->handle([
        3 => $this->importRow(),
        4 => $this->importRow(['unit' => 'قطعة', 'unit_is_default' => '', 'conversion_factor' => '1', 'base_price' => '5', 'moq' => '10', 'tier_1_min' => '', 'tier_1_price' => '']),
    ], $this->lookups);

    $units = SkuUnit::query()->orderBy('sort_order')->get();

    expect($result->action)->toBe(ImportRowAction::Create)
        ->and($result->row)->toBe(3)
        ->and($units->map(fn (SkuUnit $unit): array => [$unit->conversion_factor, $unit->base_price, $unit->moq, $unit->is_default])->all())
        ->toBe([[20, '90.00', 5, true], [1, '5.00', 10, false]]);
});

test('D91 an error of a unit is reported on that unit\'s row and nothing is written', function (): void {
    $result = app(ImportCatalogRow::class)->handle([
        3 => $this->importRow(),
        4 => $this->importRow(['unit' => 'قطعة', 'unit_is_default' => '', 'conversion_factor' => '1', 'base_price' => '200', 'tier_1_min' => '10', 'tier_1_price' => '300']),
    ], $this->lookups);

    expect($result->action)->toBe(ImportRowAction::Skip)
        ->and($result->rowErrors())->toBe([['row' => 4, 'column' => 'tiers', 'label' => 'شرائح السعر', 'message' => 'سعر شريحة 10 أعلى من السعر الأساسي']])
        ->and(Product::query()->count())->toBe(0);
});
