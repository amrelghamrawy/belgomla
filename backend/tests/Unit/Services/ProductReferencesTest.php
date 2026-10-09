<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

function referenceData(int $categoryId, int $sellingUnitId, int $baseUnitId, string $code = 'PEN-1'): ProductData
{
    return new ProductData('قلم', $categoryId, new SkuData($code, $sellingUnitId, $baseUnitId, 1, '10.00'));
}

test('valid references pass', function (): void {
    $basics = $this->catalogBasics();
    $data = referenceData($basics['category']->id, $basics['carton']->id, $basics['piece']->id);

    expect(fn () => app(ProductReferences::class)->check($data))->not->toThrow(Throwable::class);
});

test('BR-T04 unknown references are reported on their field', function (array $ids, string $field): void {
    $basics = $this->catalogBasics();
    $ids += ['category' => $basics['category']->id, 'selling' => $basics['carton']->id, 'base' => $basics['piece']->id];

    try {
        app(ProductReferences::class)->check(referenceData($ids['category'], $ids['selling'], $ids['base']));
        $this->fail('Expected InvalidCatalogInput');
    } catch (InvalidCatalogInput $e) {
        expect($e->details)->toHaveKey($field);
    }
})->with([
    'category' => [['category' => 999_999], 'category_id'],
    'selling unit' => [['selling' => 999_999], 'sku.selling_unit_id'],
    'base unit' => [['base' => 999_999], 'sku.base_unit_id'],
]);

test('an archived category or unit may be kept by the product that already uses it', function (): void {
    $category = Category::factory()->archived()->create();
    $unit = Unit::factory()->archived()->create();
    $product = Product::factory()->withSku(['code' => 'PEN-1', 'selling_unit_id' => $unit->id, 'base_unit_id' => $unit->id])->create(['category_id' => $category->id]);

    expect(fn () => app(ProductReferences::class)->check(referenceData($category->id, $unit->id, $unit->id), $product->load('sku')))
        ->not->toThrow(Throwable::class);
});

test('an archived category cannot be newly chosen', function (): void {
    $basics = $this->catalogBasics();
    $archived = Category::factory()->archived()->create();

    app(ProductReferences::class)->check(referenceData($archived->id, $basics['carton']->id, $basics['piece']->id));
})->throws(InvalidCatalogInput::class);

test('an archived unit cannot be newly chosen', function (): void {
    $basics = $this->catalogBasics();
    $archived = Unit::factory()->archived()->create();

    app(ProductReferences::class)->check(referenceData($basics['category']->id, $basics['carton']->id, $archived->id));
})->throws(InvalidCatalogInput::class);

test('BR-P03 a taken sku code conflicts unless it is the product own sku', function (): void {
    $basics = $this->catalogBasics();
    $product = Product::factory()->withSku(['code' => 'PEN-1'])->create();
    $data = referenceData($basics['category']->id, $basics['carton']->id, $basics['piece']->id);

    expect(fn () => app(ProductReferences::class)->check($data, $product->load('sku')))->not->toThrow(Throwable::class)
        ->and(fn () => app(ProductReferences::class)->check($data))->toThrow(CatalogConflict::class);
});

test('BR-P03 codes of another tenant do not conflict', function (): void {
    $basics = $this->catalogBasics();
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'PEN-1']), $this->otherTenant());
    $data = referenceData($basics['category']->id, $basics['carton']->id, $basics['piece']->id);

    expect(fn () => app(ProductReferences::class)->check($data))->not->toThrow(Throwable::class);
});
