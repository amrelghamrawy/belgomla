<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\GenerateGradeSet;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\BundleRefused;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\BundlePricing;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;

beforeEach(function (): void {
    $basics = $this->importBasics();
    $this->basics = $basics;
    $this->book = fn (string $name, string $code, string $publisher, ProductStatus $status = ProductStatus::Active): Sku => $this->inTenant(function () use ($name, $code, $publisher, $status, $basics): Sku {
        $sku = $this->orderableSku(['code' => $code], status: $status, name: $name);
        Product::query()->whereKey($sku->product_id)->update(['category_id' => $basics['grade']->id, 'custom_fields' => json_encode(['publisher' => $basics[$publisher]->id])]);

        return $sku;
    });
    $this->science = ($this->book)('علوم', 'SC-1', 'adwaa');
    $this->arabic = ($this->book)('عربي', 'AR-1', 'adwaa');
    $this->other = ($this->book)('رياضيات المعلم', 'MA-1', 'moallem');
    app(TenantContext::class)->set($this->tenant);

    $this->filter = new ProductFilterData(categoryId: $basics['grade']->id, fields: ['publisher' => (string) $basics['adwaa']->id]);
});

test('D93 a grade set holds the default unit of every matching active product, once each, in name order', function (): void {
    $set = app(GenerateGradeSet::class)->handle($this->filter);

    expect(BundleItem::query()->where('bundle_sku_id', $this->bundleSku($set)->id)->orderBy('sort_order')->get(['component_sku_unit_id', 'quantity'])->toArray())->toBe([
        ['component_sku_unit_id' => $this->defaultUnitId($this->arabic), 'quantity' => 1],
        ['component_sku_unit_id' => $this->defaultUnitId($this->science), 'quantity' => 1],
    ]);
});

test('D93 the set is an inactive draft named after the selection, coded SET-n, at the plain sum', function (): void {
    $set = app(GenerateGradeSet::class)->handle($this->filter);

    expect($set->only(['name', 'status', 'category_id']))->toBe(['name' => 'طقم أولى إعدادي - الأضواء', 'status' => ProductStatus::Inactive, 'category_id' => $this->basics['grade']->id])
        ->and($this->bundleSku($set)->code)->toBe('SET-1')
        ->and(BundlePricing::query()->sole()->only(['mode', 'value']))->toBe(['mode' => BundlePriceMode::PercentOffSum, 'value' => '0.00'])
        ->and(SkuUnit::query()->where('sku_id', $this->bundleSku($set)->id)->value('base_price'))->toBe('180.00');
});

test('D93 the set keeps the custom field values its components share', function (): void {
    $set = app(GenerateGradeSet::class)->handle($this->filter);

    expect($set->custom_fields)->toBe(['publisher' => $this->basics['adwaa']->id]);
});

test('D93 the set is sold in the store unit «طقم» when there is one', function (): void {
    $unit = Unit::factory()->create(['name' => 'طقم']);

    $set = app(GenerateGradeSet::class)->handle($this->filter);

    expect($this->bundleSku($set)->base_unit_id)->toBe($unit->id);
});

test('D93 a taken SET code moves to the next number', function (): void {
    app(GenerateGradeSet::class)->handle($this->filter);

    expect($this->bundleSku(app(GenerateGradeSet::class)->handle($this->filter))->code)->toBe('SET-2');
});

test('D93 inactive products and bundles are left out', function (): void {
    ($this->book)('دراسات', 'ST-1', 'adwaa', ProductStatus::Inactive);
    app(GenerateGradeSet::class)->handle($this->filter);

    $second = app(GenerateGradeSet::class)->handle($this->filter);

    expect(BundleItem::query()->where('bundle_sku_id', $this->bundleSku($second)->id)->count())->toBe(2);
});

test('BR-P12 an empty filter is refused', function (): void {
    expect(fn () => app(GenerateGradeSet::class)->handle(new ProductFilterData))->toThrow(function (BundleRefused $e): void {
        expect($e->errorCode)->toBe('validation_failed')->and(array_keys($e->details ?? []))->toBe(['filter']);
    });
});

test('D93 a selection without any active product is refused', function (): void {
    app(GenerateGradeSet::class)->handle(new ProductFilterData(q: 'لا يوجد'));
})->throws(BundleRefused::class, 'بعض البيانات غير صحيحة');

test('D93 a selection of more than 50 products is refused', function (): void {
    foreach (range(1, 49) as $index) {
        ($this->book)("كتاب {$index}", "BK-{$index}", 'adwaa');
    }

    expect(fn () => app(GenerateGradeSet::class)->handle($this->filter))->toThrow(function (ApiException $e): void {
        expect($e->details)->toBe(['filter' => ['الاختيار يضم أكثر من 50 منتجًا؛ ضيّق الاختيار']]);
    });
});
