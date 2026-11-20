<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Services\BundleSheet;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->book = $this->importedProduct();
    $this->sheet = app(BundleSheet::class);
});

test('D93 a bundle is one row per component with the bundle, its pricing and its price', function (): void {
    $this->importedBundle($this->book);

    $sheet = $this->sheet->sheet(new ProductFilterData, app(CatalogRowMapper::class)->lookups());

    expect($sheet['name'])->toBe('bundles_v1')
        ->and($sheet['headers'])->toBe(BundleSheet::LABELS)
        ->and(iterator_to_array($sheet['rows'], false))->toBe([[
            'bundle_code' => 'SET-1',
            'bundle_name' => 'باقة أولى إعدادي',
            'status' => 'نشط',
            'category_path' => 'كتب خارجية > أولى إعدادي',
            'unit' => 'قطعة',
            'moq' => 2,
            'pricing_mode' => 'خصم من مجموع الأصناف',
            'pricing_value' => 10,
            'bundle_price' => 162,
            'component_code' => 'ADW-SCI-1',
            'component_unit' => 'كرتونة',
            'component_quantity' => 2,
        ]]);
});

test('D93 the components of a bundle follow the merchant\'s order', function (): void {
    $bundle = $this->importedBundle($this->book);
    $second = $this->importedProduct(['code' => 'ADW-MATH-1']);
    $bundle->skus()->sole()->bundleItems()->create(['component_sku_unit_id' => $this->defaultUnitId($second->skus()->sole()), 'quantity' => 1, 'sort_order' => -1]);

    $rows = iterator_to_array($this->sheet->sheet(new ProductFilterData, app(CatalogRowMapper::class)->lookups())['rows'], false);

    expect(array_column($rows, 'component_code'))->toBe(['ADW-MATH-1', 'ADW-SCI-1'])
        ->and(array_column($rows, 'bundle_code'))->toBe(['SET-1', 'SET-1']);
});

test('D93 the sheet follows the export filter and leaves simple products out', function (string $q, array $codes): void {
    $this->importedBundle($this->book);

    $rows = iterator_to_array($this->sheet->sheet(new ProductFilterData(q: $q), app(CatalogRowMapper::class)->lookups())['rows'], false);

    expect(array_column($rows, 'bundle_code'))->toBe($codes);
})->with([
    'matching the bundle' => ['باقة', ['SET-1']],
    'matching only the book' => ['علوم', []],
]);

test('D93 a bundle without components is still one row', function (): void {
    $bundle = $this->importedBundle($this->book);
    $bundle->skus()->sole()->bundleItems()->delete();

    $rows = $this->sheet->rows($bundle->load('skus.defaultUnit.unit', 'skus.bundleItems'), app(CatalogRowMapper::class)->lookups());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['bundle_code'])->toBe('SET-1')
        ->and(array_key_exists('component_code', $rows[0]))->toBeFalse();
});
