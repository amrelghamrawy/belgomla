<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

function referenceData(int $categoryId, int $sellingUnitId, int $baseUnitId, string $code = 'PEN-1', ?int $skuUnitId = null): ProductData
{
    return new ProductData('قلم', $categoryId, [new SkuData($code, $baseUnitId, [new SkuUnitData($skuUnitId, $sellingUnitId, 1, true, new SkuPricingData('10.00'))])]);
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
    'D91 a unit sold in' => [['selling' => 999_999], 'sku.units.0.unit_id'],
    'base unit' => [['base' => 999_999], 'sku.base_unit_id'],
]);

test('an archived category or unit may be kept by the product that already uses it', function (): void {
    $category = Category::factory()->archived()->create();
    $unit = Unit::factory()->archived()->create();
    $product = Product::factory()->withSku(['code' => 'PEN-1', 'selling_unit_id' => $unit->id, 'base_unit_id' => $unit->id])->create(['category_id' => $category->id]);

    expect(fn () => app(ProductReferences::class)->check(referenceData($category->id, $unit->id, $unit->id), $product->load('skus')))
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

test('D91 a unit id that is not one of the product sku units is reported on its line', function (): void {
    $basics = $this->catalogBasics();
    $product = Product::factory()->withSku(['code' => 'PEN-1'])->create();
    $foreign = Sku::factory()->create();

    try {
        app(ProductReferences::class)->check(referenceData($basics['category']->id, $basics['carton']->id, $basics['piece']->id, skuUnitId: $this->defaultUnitId($foreign)), $product->load('skus'));
        $this->fail('Expected InvalidCatalogInput');
    } catch (InvalidCatalogInput $e) {
        expect($e->details)->toBe(['sku.units.0.id' => ['العنصر المختار غير موجود']]);
    }
});

test('D91 an archived store unit the sku only sold in an archived unit cannot be chosen again', function (): void {
    $basics = $this->catalogBasics();
    $unit = Unit::factory()->archived()->create();
    $product = Product::factory()->withSku(['code' => 'PEN-1'])->create();
    SkuUnit::factory()->archived()->create(['sku_id' => $product->skus()->sole()->id, 'unit_id' => $unit->id]);

    app(ProductReferences::class)->check(referenceData($basics['category']->id, $unit->id, $basics['piece']->id), $product->load('skus'));
})->throws(InvalidCatalogInput::class);

test('BR-P03 a taken sku code conflicts unless it is the product own sku', function (): void {
    $basics = $this->catalogBasics();
    $product = Product::factory()->withSku(['code' => 'PEN-1'])->create();
    $data = referenceData($basics['category']->id, $basics['carton']->id, $basics['piece']->id);

    expect(fn () => app(ProductReferences::class)->check($data, $product->load('skus')))->not->toThrow(Throwable::class)
        ->and(fn () => app(ProductReferences::class)->check($data))->toThrow(CatalogConflict::class);
});

test('BR-P03 codes of another tenant do not conflict', function (): void {
    $basics = $this->catalogBasics();
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'PEN-1']), $this->otherTenant());
    $data = referenceData($basics['category']->id, $basics['carton']->id, $basics['piece']->id);

    expect(fn () => app(ProductReferences::class)->check($data))->not->toThrow(Throwable::class);
});

test('BR-P03 BR-P14 a variant code taken by another product is a conflict named after the variant', function (): void {
    $basics = $this->catalogBasics();
    Product::factory()->withSku(['code' => 'PEN-2'])->create();
    $unit = new SkuUnitData(null, $basics['piece']->id, 1, true, new SkuPricingData('10.00'));
    $data = new ProductData('قلم', $basics['category']->id, [
        new SkuData('PEN-1', $basics['piece']->id, [$unit], optionValues: ['أزرق'], path: 'skus.0'),
        new SkuData('PEN-2', $basics['piece']->id, [$unit], optionValues: ['أسود'], path: 'skus.1'),
    ]);

    try {
        app(ProductReferences::class)->check($data);
        $this->fail('Expected CatalogConflict');
    } catch (CatalogConflict $e) {
        expect($e->errorCode)->toBe('duplicate_code')
            ->and($e->details)->toBe(['skus.1.code' => ['كود الصنف مستخدم بالفعل']]);
    }
});

test('BR-T04 BR-P14 option, value, sku and swatch ids must be the product own', function (string $field, string $argument): void {
    $pen = $this->variantProduct(['أزرق']);
    $other = $this->variantProduct(['أحمر'], code: 'OTH');
    $ids = [
        'optionId' => $other->options()->sole()->id,
        'valueId' => App\Modules\Catalog\Models\ProductOptionValue::query()->where('value', 'أحمر')->sole()->id,
        'skuId' => $other->skus()->sole()->id,
        'imageId' => App\Modules\Catalog\Models\ProductImage::factory()->create(['product_id' => $other->id])->id,
    ];

    try {
        app(ProductReferences::class)->check(penReferenceData($pen, ...[$argument => $ids[$argument]]), $pen);
        $this->fail('Expected InvalidCatalogInput');
    } catch (InvalidCatalogInput $e) {
        expect($e->details)->toHaveKey($field);
    }
})->with([
    'an option of another product' => ['options.0.id', 'optionId'],
    'a value of another product' => ['options.0.values.0.id', 'valueId'],
    'a sku of another product' => ['skus.0.id', 'skuId'],
    'an image of another product' => ['options.0.values.0.image_id', 'imageId'],
]);

function penReferenceData(Product $pen, ?int $optionId = null, ?int $valueId = null, ?int $skuId = null, ?int $imageId = null): ProductData
{
    $piece = $pen->skus()->sole()->base_unit_id;

    return new ProductData('قلم', $pen->category_id, [
        new SkuData('PEN-1', $piece, [new SkuUnitData(null, $piece, 1, true, new SkuPricingData('10.00'))], id: $skuId, optionValues: ['أزرق'], path: 'skus.0'),
    ], [new App\Modules\Catalog\Data\ProductOptionData($optionId, 'اللون', [new App\Modules\Catalog\Data\ProductOptionValueData($valueId, 'أزرق', $imageId)])]);
}
