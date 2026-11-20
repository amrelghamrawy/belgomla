<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ImportRowData;
use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCustomFields;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Exceptions\InvalidImportRow;
use App\Modules\Catalog\Exceptions\InvalidProductTransition;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Inventory\Exceptions\BelowReserved;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidTiers;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->basics = $this->importBasics();
    $this->mapper = app(CatalogRowMapper::class);
    $this->penRow = fn (string $code, string $color, array $overrides = []): array => $this->importRow([
        'product_key' => 'PEN', 'sku_code' => $code, 'product_name' => 'قلم جاف',
        'variant_option_1' => 'اللون', 'variant_value_1' => $color, 'variant_option_2' => 'المقاس', 'variant_value_2' => 'M', 'sku_status' => '',
        'unit' => 'قطعة', 'conversion_factor' => '1', 'cover_price' => '7', 'base_price' => '5', 'moq' => '10', 'tier_1_min' => '', 'tier_1_price' => '', 'on_hand' => '100',
        ...$overrides,
    ]);
});

/**
 * The errors of the rows of one SKU, by row number and column.
 *
 * @param  non-empty-array<int, array<string, string>>  $rows
 * @return array<int, array<string, list<string>>>
 */
function mapperRowErrors(CatalogRowMapper $mapper, array $rows, ?Product $existing = null): array
{
    try {
        $mapper->map($rows, $mapper->lookups(), $existing);
    } catch (InvalidImportRow $e) {
        $errors = [];

        foreach ($e->items ?? [] as $item) {
            $errors[$item['row']][$item['column']][] = $item['message'];
        }

        return $errors;
    }

    return [];
}

/**
 * The errors of a SKU of one row (row 2), by column.
 *
 * @param  array<string, string>  $row
 * @return array<string, list<string>>
 */
function mapperErrors(CatalogRowMapper $mapper, array $row, ?Product $existing = null): array
{
    return mapperRowErrors($mapper, [2 => $row], $existing)[2] ?? [];
}

/**
 * A SKU of one row (row 2).
 *
 * @param  array<string, string>  $row
 */
function mapperRow(CatalogRowMapper $mapper, array $row, ?Product $existing = null): ImportRowData
{
    return $mapper->map([2 => $row], $mapper->lookups(), $existing);
}

/**
 * @param  list<list<string>>  $cells
 * @return array<int, array<string, string>>
 */
function mapperRows(CatalogRowMapper $mapper, array $cells, ?string $sheetName = null): array
{
    $raw = [];

    foreach ($cells as $index => $row) {
        $raw[$index + 1] = $row;
    }

    return iterator_to_array($mapper->rows($raw, $mapper->lookups(), $sheetName));
}

test('the columns are the fixed ones, five tier pairs, on hand, then the active custom fields in form order', function (): void {
    ProductFieldDefinition::factory()->archived()->create(['key' => 'old']);

    $columns = $this->mapper->columns($this->mapper->lookups());

    expect(array_keys($columns))->toBe([
        'product_key', 'sku_code', 'product_name', 'category_path', 'status', 'description',
        'variant_option_1', 'variant_value_1', 'variant_option_2', 'variant_value_2', 'sku_status',
        'base_unit', 'unit', 'unit_is_default', 'conversion_factor',
        'cover_price', 'base_price', 'base_price_percent', 'moq',
        'tier_1_min', 'tier_1_price', 'tier_2_min', 'tier_2_price', 'tier_3_min', 'tier_3_price', 'tier_4_min', 'tier_4_price', 'tier_5_min', 'tier_5_price',
        'on_hand', 'field:publisher', 'field:brand',
    ])->and($columns['sku_code'])->toBe('كود الصنف')
        ->and($columns['product_key'])->toBe('مفتاح المنتج')
        ->and($columns['variant_option_2'])->toBe('الخيار 2')
        ->and($columns['variant_value_2'])->toBe('القيمة 2')
        ->and($columns['sku_status'])->toBe('حالة الصنف')
        ->and($columns['unit_is_default'])->toBe('الوحدة الافتراضية')
        ->and($columns['tier_2_price'])->toBe('شريحة 2: السعر')
        ->and($columns['field:publisher'])->toBe('الشركة');
});

test('a sku unit with more than five tiers gets a tier column pair for each', function (): void {
    $product = $this->importedProduct();
    foreach ([20, 30, 40, 50, 60] as $min) {
        SkuPriceTier::factory()->create(['sku_id' => $product->skus->first()?->id, 'min_quantity' => $min, 'unit_price' => '80.00']);
    }

    expect($this->mapper->lookups()->tierColumns)->toBe(6)
        ->and($this->mapper->columns($this->mapper->lookups()))->toHaveKey('tier_6_price');
});

test('the key row under an arabic title row names the columns and data starts after it', function (): void {
    $rows = mapperRows($this->mapper, [
        ['كود الصنف', 'اسم المنتج'],
        ['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '1', '10'],
    ]);

    expect($rows)->toBe([3 => [
        'sku_code' => 'A-1', 'product_name' => 'كتاب', 'category_path' => 'كتب خارجية', 'unit' => 'كرتونة',
        'base_unit' => 'قطعة', 'conversion_factor' => '1', 'base_price' => '10',
    ]]);
});

test('D91 a format v1 key row is read with its selling unit as the unit', function (): void {
    $rows = mapperRows($this->mapper, [
        ['sku_code', 'product_name', 'category_path', 'selling_unit', 'base_unit', 'conversion_factor', 'base_price'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '20', '10'],
    ], 'products_v1');

    expect($rows[2])->toBe([
        'sku_code' => 'A-1', 'product_name' => 'كتاب', 'category_path' => 'كتب خارجية', 'unit' => 'كرتونة',
        'base_unit' => 'قطعة', 'conversion_factor' => '20', 'base_price' => '10',
    ]);
});

test('a csv with the key row first is read from its second row', function (): void {
    $rows = mapperRows($this->mapper, [
        ['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '1', '10'],
    ]);

    expect(array_keys($rows))->toBe([2]);
});

test('a file with only the arabic label row is read through the labels', function (): void {
    $rows = mapperRows($this->mapper, [
        ['كود الصنف', 'اسم المنتج', 'التصنيف', 'وحدة البيع', 'الوحدة الأساسية', 'عدد الوحدات الأساسية في وحدة البيع', 'سعر الجملة', 'الشركة'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '1', '10', 'الأضواء'],
    ]);

    expect($rows[2])->toBe([
        'sku_code' => 'A-1', 'product_name' => 'كتاب', 'category_path' => 'كتب خارجية', 'unit' => 'كرتونة',
        'base_unit' => 'قطعة', 'conversion_factor' => '1', 'base_price' => '10', 'field:publisher' => 'الأضواء',
    ]);
});

test('an unknown column is ignored', function (): void {
    $rows = mapperRows($this->mapper, [
        ['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price', 'notes', 'field:nothing'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '1', '10', 'ملاحظة', 'x'],
    ]);

    expect($rows[2])->not->toHaveKey('notes')
        ->and($rows[2])->not->toHaveKey('field:nothing')
        ->and(mapperRow($this->mapper, $this->importRow(['field:nothing' => 'x']))->product->customFields)->toBe(['publisher' => $this->basics['adwaa']->id]);
});

test('a file is refused as a whole when its header is wrong', function (array $cells, string $message, ?string $sheet): void {
    expect(fn (): array => mapperRows($this->mapper, $cells, $sheet))->toThrow(function (InvalidImportFile $e) use ($message): void {
        expect($e->details)->toBe(['file' => [$message]]);
    });
})->with([
    'a required column missing' => [[['sku_code', 'product_name'], ['A', 'B']], 'أعمدة ناقصة في الملف: التصنيف، وحدة البيع، الوحدة الأساسية، عدد الوحدات الأساسية في وحدة البيع، سعر الجملة', null],
    'no header at all' => [[['A-1', 'كتاب'], ['A-2', 'قلم']], 'لم يتم التعرف على صف العناوين، استخدم القالب', null],
    'one unknown row' => [[['A-1', 'كتاب']], 'لم يتم التعرف على صف العناوين، استخدم القالب', null],
    'nothing in it' => [[], 'الملف لا يحتوي على منتجات', null],
    'a newer format version' => [[['sku_code']], 'إصدار الملف «products_v4» غير مدعوم، نزّل قالبًا جديدًا', 'products_v4'],
]);

test('D91 BR-P14 the sheets of format v1, v2 and v3 are all read', function (string $sheet): void {
    expect(mapperRows($this->mapper, [['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price']], $sheet))->toBe([]);
})->with(['v1' => ['products_v1'], 'v2' => ['products_v2'], 'v3' => ['products_v3']]);

test('a key row without data rows yields nothing', function (): void {
    expect(mapperRows($this->mapper, [['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price']], 'Sheet1'))->toBe([]);
});

test('BR-P14 consecutive rows sharing a product key are one product, an empty key being the code, so a unit row may leave it empty', function (): void {
    $products = iterator_to_array($this->mapper->products([
        2 => ['product_key' => 'PEN', 'sku_code' => 'PEN-BLUE'],
        3 => ['product_key' => '', 'sku_code' => 'PEN-BLUE'],
        4 => ['product_key' => ' PEN ', 'sku_code' => 'PEN-RED'],
        5 => ['product_key' => '', 'sku_code' => 'BOOK-1'],
        6 => ['product_key' => '', 'sku_code' => 'BOOK-1'],
        7 => ['product_key' => '', 'sku_code' => ''],
        8 => ['product_key' => '', 'sku_code' => ''],
        9 => ['sku_code' => 'V2-1'],
    ]));

    expect(array_map(fn (array $product): array => array_keys($product['rows']), $products))->toBe([2 => [2, 3, 4], 5 => [5, 6], 7 => [7], 8 => [8], 9 => [9]])
        ->and(array_filter(array_column($products, 'duplicates')))->toBe([]);
});

test('BR-P14 a product key or a code that comes back after another product, or a code after another code, is a duplicate', function (): void {
    $products = iterator_to_array($this->mapper->products([
        2 => ['product_key' => 'PEN', 'sku_code' => 'PEN-BLUE'],
        3 => ['product_key' => 'BOOK', 'sku_code' => 'BOOK-1'],
        4 => ['product_key' => 'PEN', 'sku_code' => 'PEN-RED'],
        5 => ['product_key' => 'BAG', 'sku_code' => 'BOOK-1'],
        6 => ['product_key' => 'CUP', 'sku_code' => 'CUP-1'],
        7 => ['product_key' => 'CUP', 'sku_code' => 'CUP-2'],
        8 => ['product_key' => 'CUP', 'sku_code' => 'CUP-1'],
        9 => ['product_key' => '', 'sku_code' => 'CUP-2'],
    ]));

    expect(array_map(fn (array $product): array => $product['duplicates'], $products))->toBe([
        2 => [],
        3 => [],
        4 => [4 => ['product_key' => ['مفتاح المنتج مكرر في الملف (الصف 2)']]],
        5 => [5 => ['sku_code' => ['الكود مكرر في الملف (الصف 3)']]],
        6 => [8 => ['sku_code' => ['الكود مكرر في الملف (الصف 6)']]],
        9 => [9 => ['sku_code' => ['الكود مكرر في الملف (الصف 7)']]],
    ]);
});

test('BR-P14 the rows of a product with variants become its options and one SKU per variant, each with its rows and stock', function (): void {
    $data = $this->mapper->map([
        2 => ($this->penRow)('PEN-BLUE', 'أزرق'),
        3 => ($this->penRow)('PEN-RED', ' أحمر ', ['variant_option_1' => 'اللّون', 'sku_status' => 'غير نشط', 'on_hand' => '50']),
        4 => ($this->penRow)('PEN-RED', 'أحمر', ['product_key' => '', 'unit' => 'كرتونة', 'unit_is_default' => '', 'conversion_factor' => '12', 'base_price' => '55']),
    ], $this->mapper->lookups());

    expect(array_map(fn (ProductOptionData $option): array => [$option->id, $option->name, array_map(fn (ProductOptionValueData $value): string => $value->value, $option->values)], $data->product->options))
        ->toBe([[null, 'اللون', ['أزرق', 'أحمر']], [null, 'المقاس', ['M']]])
        ->and(array_map(fn (SkuData $sku): array => [$sku->code, $sku->optionValues, $sku->status, $sku->openingStock, $sku->path, count($sku->units)], $data->product->skus))->toBe([
            ['PEN-BLUE', ['أزرق', 'M'], SkuStatus::Active, 100, 'skus.0', 1],
            ['PEN-RED', ['أحمر', 'M'], SkuStatus::Inactive, 50, 'skus.1', 2],
        ])
        ->and($data->onHand)->toBe([0 => 100, 1 => 50])
        ->and($data->rows)->toBe([[2], [3, 4]]);
});

test('BR-P14 a variant error names its own row and cell', function (array $blue, array $red, array $errors): void {
    expect(mapperRowErrors($this->mapper, [2 => ($this->penRow)('PEN-BLUE', 'أزرق', $blue), 3 => ($this->penRow)('PEN-RED', 'أحمر', $red)]))->toBe($errors);
})->with([
    'an option without a value' => [[], ['variant_value_1' => ''], [3 => ['variant_value_1' => ['اكتب قيمة الخيار']]]],
    'a value without an option' => [[], ['variant_option_2' => ''], [3 => ['variant_option_2' => ['اكتب اسم الخيار'], 'variant_value_2' => ['اكتب قيمة «المقاس» لهذا الصنف']]]],
    'the same option twice' => [[], ['variant_option_2' => 'اللون'], [3 => ['variant_option_2' => ['الخيار مكرر في هذا الصنف'], 'variant_value_2' => ['اكتب قيمة «المقاس» لهذا الصنف']]]],
    'D92 a third option' => [[], ['variant_option_2' => 'الخامة', 'variant_value_2' => 'معدن'], [3 => ['variant_option_2' => ['للمنتج خياران على الأكثر'], 'variant_value_2' => ['اكتب قيمة «المقاس» لهذا الصنف']]]],
    'the same combination twice' => [[], ['variant_value_1' => 'ازرق'], [3 => ['variant_value_1' => ['هذه التركيبة مكررة في المنتج']]]],
    'two SKUs without options' => [
        ['variant_option_1' => '', 'variant_value_1' => '', 'variant_option_2' => '', 'variant_value_2' => ''],
        ['variant_option_1' => '', 'variant_value_1' => '', 'variant_option_2' => '', 'variant_value_2' => ''],
        [3 => ['variant_option_1' => ['المنتج بأكثر من صنف يحتاج خيارًا وقيمة لكل صنف']]],
    ],
    'no active SKU' => [['sku_status' => 'غير نشط'], ['sku_status' => 'inactive'], [2 => ['sku_status' => ['صنف واحد نشط على الأقل']]]],
    'an unknown SKU status' => [['sku_status' => 'مخفي'], [], [2 => ['sku_status' => ['قيمة حالة الصنف غير صالحة.']]]],
    'a unit of the second variant' => [[], ['base_price' => '0'], [3 => ['base_price' => ['يجب أن تكون قيمة سعر الجملة أكبر من 0.']]]],
    'a code of the second variant' => [[], ['sku_code' => 'PEN RED'], [3 => ['sku_code' => ['صيغة كود الصنف غير صالحة.']]]],
]);

test('BR-P14 the SKU of a product without options is always active', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['sku_status' => 'غير نشط'])))->toBe(['sku_status' => ['صنف المنتج بدون خيارات نشط دائمًا']])
        ->and(mapperRow($this->mapper, $this->importRow(['sku_status' => 'نشط']))->product->skus[0]->path)->toBe('sku');
});

test('BR-P14 errors of the actions about a variant point at that variant\'s rows', function (): void {
    $errors = $this->mapper->errors(
        new ApiException(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['skus.1.units.1.unit_id' => ['العنصر المختار مؤرشف'], 'skus.0.code' => ['خطأ'], 'options.0.values.1.id' => ['خطأ']]),
        $this->mapper->lookups(),
        [[9], [7, 8]],
    );

    expect(array_map(fn (array $error): array => [$error['row'] ?? null, $error['column']], $errors))->toBe([[8, 'unit'], [9, 'sku_code'], [7, 'variant_option_1']]);
});

test('D91 consecutive rows of one code are one SKU, a row without a code stands alone', function (): void {
    $skus = iterator_to_array($this->mapper->skus([
        2 => ['sku_code' => 'A'],
        3 => ['sku_code' => 'A'],
        4 => ['sku_code' => 'B'],
        5 => ['sku_code' => ''],
        6 => ['sku_code' => ''],
        7 => ['sku_code' => 'A'],
    ]));

    expect($skus)->toBe([
        2 => [2 => ['sku_code' => 'A'], 3 => ['sku_code' => 'A']],
        4 => [4 => ['sku_code' => 'B']],
        5 => [5 => ['sku_code' => '']],
        6 => [6 => ['sku_code' => '']],
        7 => [7 => ['sku_code' => 'A']],
    ]);
});

test('a valid row becomes the product data the actions take', function (): void {
    $data = mapperRow($this->mapper, $this->importRow());
    $unit = $data->product->skus[0]->units[0];

    expect($data)->toBeInstanceOf(ImportRowData::class)
        ->and($data->product->name)->toBe('الأضواء علوم أولى إعدادي')
        ->and($data->product->categoryId)->toBe($this->basics['grade']->id)
        ->and($data->product->description)->toBe('كتاب العلوم')
        ->and($data->product->customFields)->toBe(['publisher' => $this->basics['adwaa']->id])
        ->and($data->product->skus[0]->code)->toBe('ADW-SCI-1')
        ->and($data->product->skus[0]->baseUnitId)->toBe($this->basics['piece']->id)
        ->and($data->product->skus[0]->units)->toHaveCount(1)
        ->and($unit->id)->toBeNull()
        ->and($unit->unitId)->toBe($this->basics['carton']->id)
        ->and($unit->conversionFactor)->toBe(20)
        ->and($unit->isDefault)->toBeTrue()
        ->and($unit->pricing->coverPrice)->toBe('120.00')
        ->and($unit->pricing->basePrice)->toBe('90.00')
        ->and($unit->pricing->moq)->toBe(5)
        ->and($unit->pricing->tiers)->toEqual([new TierData(10, '85.00')])
        ->and($data->product->status)->toBe(ProductStatus::Active)
        ->and($data->product->skus[0]->openingStock)->toBe(400)
        ->and($data->status)->toBe(ProductStatus::Active)
        ->and($data->onHand)->toBe([0 => 400]);
});

test('D91 a format v1 row is a SKU in its one unit, the default', function (): void {
    $row = $this->importRow(['unit' => null, 'unit_is_default' => null]);
    $row['selling_unit'] = 'كرتونة';
    $raw = [1 => array_keys($row), 2 => array_values($row)];

    $rows = iterator_to_array($this->mapper->rows($raw, $this->mapper->lookups(), 'products_v1'));
    $units = $this->mapper->map($rows, $this->mapper->lookups())->product->skus[0]->units;

    expect($units)->toHaveCount(1)
        ->and($units[0]->unitId)->toBe($this->basics['carton']->id)
        ->and($units[0]->isDefault)->toBeTrue();
});

test('D91 the rows of a SKU are its units, each with its own factor, prices, MOQ and tiers, the default from the file', function (): void {
    $data = $this->mapper->map([
        2 => $this->importRow(['unit_is_default' => 'لا']),
        3 => $this->importRow(['unit' => 'قطعة', 'unit_is_default' => 'نعم', 'conversion_factor' => '1', 'cover_price' => '7', 'base_price' => '5', 'moq' => '10', 'tier_1_min' => '50', 'tier_1_price' => '4.5', 'product_name' => 'اسم آخر يتجاهل']),
    ], $this->mapper->lookups());

    [$carton, $piece] = $data->product->skus[0]->units;

    expect($data->product->name)->toBe('الأضواء علوم أولى إعدادي')
        ->and($carton->unitId)->toBe($this->basics['carton']->id)
        ->and($carton->isDefault)->toBeFalse()
        ->and($carton->conversionFactor)->toBe(20)
        ->and($carton->pricing->basePrice)->toBe('90.00')
        ->and($piece->unitId)->toBe($this->basics['piece']->id)
        ->and($piece->isDefault)->toBeTrue()
        ->and($piece->conversionFactor)->toBe(1)
        ->and($piece->pricing->coverPrice)->toBe('7.00')
        ->and($piece->pricing->basePrice)->toBe('5.00')
        ->and($piece->pricing->moq)->toBe(10)
        ->and($piece->pricing->tiers)->toEqual([new TierData(50, '4.50')]);
});

test('D91 a new SKU without a default named takes its first row as the default', function (): void {
    $units = $this->mapper->map([
        2 => $this->importRow(['unit_is_default' => '']),
        3 => $this->importRow(['unit' => 'قطعة', 'unit_is_default' => '', 'conversion_factor' => '1', 'base_price' => '5']),
    ], $this->mapper->lookups())->product->skus[0]->units;

    expect(array_map(fn (SkuUnitData $unit): bool => $unit->isDefault, $units))->toBe([true, false]);
});

test('D91 a unit the SKU has and the file does not list is kept as stored, and the SKU keeps its default', function (): void {
    $product = $this->importedProduct();
    $piece = $this->addUnit($product->skus->first(), ['unit_id' => $this->basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10], [50 => '4.50']);

    $units = mapperRow($this->mapper, $this->importRow(['unit_is_default' => '', 'base_price' => '88']), $product->load('skus.activeUnits.tiers'))->product->skus[0]->units;

    expect($units)->toHaveCount(2)
        ->and($units[0]->id)->toBe($this->defaultUnitId($product->skus->first()))
        ->and($units[0]->isDefault)->toBeTrue()
        ->and($units[0]->pricing->basePrice)->toBe('88.00')
        ->and($units[1]->id)->toBe($piece->id)
        ->and($units[1]->isDefault)->toBeFalse()
        ->and($units[1]->conversionFactor)->toBe(1)
        ->and($units[1]->pricing->basePrice)->toBe('5.00')
        ->and($units[1]->pricing->moq)->toBe(10)
        ->and($units[1]->pricing->tiers)->toEqual([new TierData(50, '4.50')]);
});

test('D91 a default named in the file moves it away from a unit the file does not list', function (): void {
    $product = $this->importedProduct();
    $this->addUnit($product->skus->first(), ['unit_id' => $this->basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '5.00']);
    $box = Unit::factory()->create(['name' => 'علبة']);

    $units = mapperRow($this->mapper, $this->importRow(['unit' => 'علبة', 'unit_is_default' => 'نعم', 'conversion_factor' => '10', 'base_price' => '45']), $product->load('skus.activeUnits.tiers'))->product->skus[0]->units;

    expect(array_map(fn (SkuUnitData $unit): array => [$unit->unitId, $unit->isDefault], $units))->toBe([
        [$box->id, true],
        [$this->basics['carton']->id, false],
        [$this->basics['piece']->id, false],
    ]);
});

test('D91 two default units of one SKU are an error on the second row', function (): void {
    $errors = mapperRowErrors($this->mapper, [
        2 => $this->importRow(),
        3 => $this->importRow(['unit' => 'قطعة', 'conversion_factor' => '1', 'base_price' => '5']),
    ]);

    expect($errors)->toBe([3 => ['unit_is_default' => ['وحدة افتراضية واحدة فقط لكل صنف']]]);
});

test('D91 a default cell other than yes or no is an error', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['unit_is_default' => 'ربما'])))->toBe(['unit_is_default' => ['اكتب نعم أو لا']]);
});

test('D91 an error about a unit is on that unit\'s row, one about the product on the first', function (): void {
    $errors = mapperRowErrors($this->mapper, [
        4 => $this->importRow(['product_name' => '']),
        5 => $this->importRow(['unit' => 'قطعة', 'unit_is_default' => '', 'conversion_factor' => '1', 'base_price' => '0', 'tier_2_min' => '', 'tier_2_price' => '3']),
    ]);

    expect($errors)->toBe([
        4 => ['product_name' => ['اسم المنتج مطلوب.']],
        5 => ['base_price' => ['يجب أن تكون قيمة سعر الجملة أكبر من 0.'], 'tier_2_min' => ['شريحة 2: من كمية مطلوب.']],
    ]);
});

test('D91 the same unit twice in one SKU is an error on its second row', function (): void {
    $errors = mapperRowErrors($this->mapper, [
        2 => $this->importRow(),
        3 => $this->importRow(['unit_is_default' => '']),
    ]);

    expect($errors[3])->toHaveKey('unit');
});

test('D25 the category is found by its full path, nested, whatever the spacing', function (string $path): void {
    expect(mapperRow($this->mapper, $this->importRow(['category_path' => $path]))->product->categoryId)->toBe($this->basics['grade']->id);
})->with([
    'as exported' => ['كتب خارجية > أولى إعدادي'],
    'tight' => ['كتب خارجية>أولى إعدادي'],
    'loose' => ['  كتب   خارجية  >  أولى إعدادي '],
]);

test('a root category is its own path', function (): void {
    expect(mapperRow($this->mapper, $this->importRow(['category_path' => 'كتب خارجية']))->product->categoryId)->toBe($this->basics['root']->id);
});

test('D25 a missing, ambiguous or archived category is a row error, never created', function (string $path, string $message): void {
    Category::factory()->create(['name' => 'مكرر']);
    Category::factory()->create(['name' => 'مكرر']);
    Category::factory()->childOf($this->basics['root'])->archived()->create(['name' => 'مؤرشف']);

    expect(mapperErrors($this->mapper, $this->importRow(['category_path' => $path])))->toBe(['category_path' => [$message]])
        ->and(Category::query()->where('name', 'غير موجود')->exists())->toBeFalse();
})->with([
    'missing' => ['كتب خارجية > غير موجود', 'التصنيف غير موجود، أنشئه أولًا من صفحة التصنيفات'],
    'ambiguous' => ['مكرر', 'يوجد أكثر من تصنيف بهذا المسار'],
    'archived' => ['كتب خارجية > مؤرشف', 'التصنيف مؤرشف'],
]);

test('a product may keep the archived category it already sits in', function (): void {
    $product = $this->importedProduct();
    $this->basics['grade']->update(['status' => 'archived']);

    expect(mapperRow($this->mapper, $this->importRow(), $product)->product->categoryId)->toBe($this->basics['grade']->id);
});

test('BR-U01 units are found by name; an unknown or archived unit is a row error', function (): void {
    Unit::factory()->archived()->create(['name' => 'دستة']);

    expect(mapperErrors($this->mapper, $this->importRow(['unit' => 'علبة', 'base_unit' => 'دستة'])))->toBe([
        'base_unit' => ['الوحدة مؤرشفة'],
        'unit' => ['الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات'],
    ]);
});

test('D91 a SKU may keep selling in an archived unit it already sells in', function (): void {
    $product = $this->importedProduct();
    $this->basics['carton']->update(['status' => 'archived']);

    expect(mapperRow($this->mapper, $this->importRow(), $product)->product->skus[0]->units[0]->unitId)->toBe($this->basics['carton']->id);
});

test('BR-P08 a select field is set by its option label', function (): void {
    expect(mapperRow($this->mapper, $this->importRow(['field:publisher' => 'المعلم']))->product->customFields)
        ->toBe(['publisher' => $this->basics['moallem']->id]);
});

test('BR-P08 an option label the field does not have is a row error', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['field:publisher' => 'سلاح التلميذ'])))->toBe(['field:publisher' => ['الاختيار غير موجود في هذا الحقل']]);
});

test('BR-P08 boolean and number fields read yes/no words and numbers', function (): void {
    ProductFieldDefinition::factory()->boolean()->create(['key' => 'colored']);
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);

    $fields = mapperRow($this->mapper, $this->importRow(['field:colored' => 'نعم', 'field:pages' => '120']))->product->customFields;

    expect($fields)->toMatchArray(['colored' => true, 'pages' => 120]);
});

test('D69 a value for a field of another section is rejected', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['field:brand' => 'فابر كاستل'])))->toBe(['field:brand' => ['هذا الحقل لا ينطبق على قسم هذا المنتج']]);
});

test('D69 a field of another section may repeat the stored value, and an empty cell keeps it', function (): void {
    $product = $this->importedProduct();
    $product->update(['custom_fields' => ['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم']]);

    $repeated = mapperRow($this->mapper, $this->importRow(['field:brand' => 'قديم']), $product->refresh());
    $empty = mapperRow($this->mapper, $this->importRow(['field:brand' => '']), $product);

    expect($repeated->product->customFields)->toEqual(['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم'])
        ->and($empty->product->customFields)->toEqual(['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم']);
});

test('an empty cell clears a field of the product\'s own section', function (): void {
    expect(mapperRow($this->mapper, $this->importRow(['field:publisher' => '']), $this->importedProduct())->product->customFields)->toBe([]);
});

test('BR-PR03 tier pairs become tiers, empty pairs are left out, no tier column keeps the tiers', function (): void {
    $two = mapperRow($this->mapper, $this->importRow(['tier_2_min' => '50', 'tier_2_price' => '80']));
    $none = mapperRow($this->mapper, $this->importRow(['tier_1_min' => '', 'tier_1_price' => '']));
    $kept = mapperRow($this->mapper, $this->importRow(['tier_1_min' => null, 'tier_1_price' => null, 'tier_2_min' => null, 'tier_2_price' => null]));

    expect($two->product->skus[0]->units[0]->pricing->tiers)->toEqual([new TierData(10, '85.00'), new TierData(50, '80.00')])
        ->and($none->product->skus[0]->units[0]->pricing->tiers)->toBe([])
        ->and($kept->product->skus[0]->units[0]->pricing->tiers)->toBeNull();
});

test('a half filled tier points at its own column', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['tier_2_min' => '', 'tier_2_price' => '80'])))->toBe(['tier_2_min' => ['شريحة 2: من كمية مطلوب.']]);
});

test('the request rules apply to every cell, with the column\'s arabic name in the message', function (array $cells, array $errors): void {
    expect(mapperErrors($this->mapper, $this->importRow($cells)))->toBe($errors);
})->with([
    'missing code' => [['sku_code' => ''], ['sku_code' => ['كود الصنف مطلوب.']]],
    'missing name' => [['product_name' => ''], ['product_name' => ['اسم المنتج مطلوب.']]],
    'bad number' => [['conversion_factor' => 'عشرين'], ['conversion_factor' => ['يجب أن يكون عدد الوحدات الأساسية في وحدة البيع عددًا صحيحًا.']]],
    'price not above zero' => [['base_price' => '0'], ['base_price' => ['يجب أن تكون قيمة سعر الجملة أكبر من 0.']]],
    'neither price nor percent' => [['base_price' => ''], ['base_price' => ['سعر الجملة مطلوب عند عدم وجود نسبة الخصم من السعر المرجعي %.']]],
    'unknown status' => [['status' => 'مخفي'], ['status' => ['قيمة الحالة غير صالحة.']]],
    'negative stock' => [['on_hand' => '-1'], ['on_hand' => ['يجب أن تكون قيمة المخزون (بالوحدة الأساسية) 0 على الأقل.']]],
]);

test('BR-P04 the status is read from its arabic label or its value', function (string $cell, ProductStatus $status): void {
    expect(mapperRow($this->mapper, $this->importRow(['status' => $cell]))->status)->toBe($status);
})->with([
    'label' => ['قريبًا', ProductStatus::ComingSoon],
    'value' => ['inactive', ProductStatus::Inactive],
]);

test('an empty status and stock leave them alone', function (): void {
    $data = mapperRow($this->mapper, $this->importRow(['status' => '', 'on_hand' => '']));

    expect($data->status)->toBeNull()
        ->and($data->onHand)->toBe([])
        ->and($data->product->skus[0]->openingStock)->toBe(0);
});

test('optional columns left out of the file keep what the product has', function (): void {
    $product = $this->importedProduct(['moq' => 7]);

    $data = mapperRow($this->mapper, $this->importRow(['description' => null, 'cover_price' => null, 'moq' => null, 'field:publisher' => null]), $product);

    expect($data->product->description)->toBe('كتاب العلوم')
        ->and($data->product->skus[0]->units[0]->pricing->coverPrice)->toBe('120.00')
        ->and($data->product->skus[0]->units[0]->pricing->moq)->toBe(7)
        ->and($data->product->customFields)->toBe(['publisher' => $this->basics['adwaa']->id]);
});

test('an empty description or reference price clears it', function (): void {
    $data = mapperRow($this->mapper, $this->importRow(['description' => '', 'cover_price' => '']), $this->importedProduct());

    expect($data->product->description)->toBeNull()
        ->and($data->product->skus[0]->units[0]->pricing->coverPrice)->toBeNull();
});

test('BR-PR10 a percentage wins over the price', function (): void {
    $pricing = mapperRow($this->mapper, $this->importRow(['base_price' => '95', 'base_price_percent' => '25']))->product->skus[0]->units[0]->pricing;

    expect($pricing->basePrice)->toBeNull()
        ->and($pricing->basePricePercent)->toBe('25.00');
});

test('D95 a row repeating the stored price and percentage keeps both, and a tier keeps its percentage', function (): void {
    $product = $this->importedProduct(['base_price' => '88.00', 'base_price_percent' => '25.00']);
    SkuPriceTier::query()->where('sku_unit_id', $this->defaultUnitId($product->skus->first()))->update(['discount_percent' => '30.00']);

    $pricing = mapperRow($this->mapper, $this->importRow(['base_price' => '88', 'base_price_percent' => '25']), $product->load('skus.activeUnits.tiers'))->product->skus[0]->units[0]->pricing;

    expect($pricing->basePrice)->toBe('88.00')
        ->and($pricing->basePricePercent)->toBe('25.00')
        ->and($pricing->tiers)->toEqual([new TierData(10, '85.00', '30.00')]);
});

test('errors of the actions point at the row and column they are about', function (Closure $exception, array $errors): void {
    expect($this->mapper->errors($exception(), $this->mapper->lookups(), [[7, 8]]))->toBe($errors);
})->with([
    'custom field' => [fn (): InvalidCustomFields => InvalidCustomFields::withErrors(['publisher' => 'هذا الحقل مطلوب']), [['row' => 7, 'column' => 'field:publisher', 'label' => 'الشركة', 'message' => 'هذا الحقل مطلوب']]],
    'D28 tiers' => [fn (): InvalidTiers => InvalidTiers::withProblems(['سعر شريحة 10 أعلى من السعر الأساسي']), [['row' => 7, 'column' => 'tiers', 'label' => 'شرائح السعر', 'message' => 'سعر شريحة 10 أعلى من السعر الأساسي']]],
    'D91 tiers of the second unit' => [fn (): ApiException => new ApiException(422, 'invalid_tiers', 'شرائح الأسعار غير صحيحة', ['sku.units.1.tiers' => ['خطأ']]), [['row' => 8, 'column' => 'tiers', 'label' => 'شرائح السعر', 'message' => 'خطأ']]],
    'D91 a reference of the second unit' => [fn (): ApiException => new ApiException(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['sku.units.1.unit_id' => ['العنصر المختار مؤرشف']]), [['row' => 8, 'column' => 'unit', 'label' => 'وحدة البيع', 'message' => 'العنصر المختار مؤرشف']]],
    'D91 a missing cover price of the second unit' => [fn (): ApiException => new ApiException(422, 'cover_price_required', 'أدخل سعر الغلاف', ['sku.units.1.cover_price' => ['أدخل سعر الغلاف']]), [['row' => 8, 'column' => 'cover_price', 'label' => 'السعر المرجعي (الغلاف أو القطاعي)', 'message' => 'أدخل سعر الغلاف']]],
    'duplicate code' => [CatalogConflict::duplicateSkuCode(...), [['row' => 7, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'كود الصنف مستخدم بالفعل']]],
    'BR-INV08 below reserved' => [fn (): BelowReserved => BelowReserved::for(5, 20, []), [['row' => 7, 'column' => 'on_hand', 'label' => 'المخزون (بالوحدة الأساسية)', 'message' => 'لا يمكن تقليل المخزون إلى 5 لأن 20 وحدة محجوزة لطلبات قائمة. ألغِ الطلبات أو سلّمها أولًا.']]],
    'BR-P04 transition' => [fn (): InvalidProductTransition => InvalidProductTransition::from(ProductStatus::Active, ProductStatus::ComingSoon), [['row' => 7, 'column' => 'status', 'label' => 'الحالة', 'message' => 'لا يمكن تغيير حالة المنتج من «نشط» إلى «قريبًا»']]],
    'rows' => [fn (): InvalidImportRow => InvalidImportRow::inRows([8 => ['tier_3_min' => ['خطأ']]]), [['row' => 8, 'column' => 'tier_3_min', 'label' => 'شريحة 3: من كمية', 'message' => 'خطأ']]],
    'anything else' => [fn (): ApiException => ApiException::conflict('other', 'رسالة'), [['row' => 7, 'column' => 'row', 'label' => 'الصف', 'message' => 'رسالة']]],
]);

test('an unknown column has its key as label', function (): void {
    expect($this->mapper->label('notes', $this->mapper->lookups()))->toBe('notes');
});

test('D91 the export rows hold the product as the import reads it, one per unit, the default first', function (): void {
    $product = $this->importedProduct(['base_price_percent' => '25.00']);
    $product->update(['custom_fields' => ['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم']]);
    $this->addUnit($product->skus->first(), ['unit_id' => $this->basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    $product = Product::query()->with(['options', 'skus.activeUnits.unit', 'skus.activeUnits.tiers', 'skus.baseUnit', 'skus.inventory', 'skus.optionValues'])->findOrFail($product->id);

    $shared = [
        'product_key' => 'ADW-SCI-1',
        'sku_code' => 'ADW-SCI-1',
        'product_name' => 'الأضواء علوم أولى إعدادي',
        'category_path' => 'كتب خارجية > أولى إعدادي',
        'status' => 'نشط',
        'description' => 'كتاب العلوم',
        'variant_option_1' => null,
        'variant_value_1' => null,
        'variant_option_2' => null,
        'variant_value_2' => null,
        'sku_status' => null,
        'base_unit' => 'قطعة',
    ];
    $fields = ['field:publisher' => 'الأضواء', 'field:brand' => 'قديم'];

    expect($this->mapper->exportRows($product, $this->mapper->lookups()))->toBe([
        [...$shared, 'unit' => 'كرتونة', 'unit_is_default' => 'نعم', 'conversion_factor' => 20, 'cover_price' => 120, 'base_price' => 90, 'base_price_percent' => 25, 'moq' => 5, 'tier_1_min' => 10, 'tier_1_price' => 85, 'on_hand' => 400, ...$fields],
        [...$shared, 'unit' => 'قطعة', 'unit_is_default' => 'لا', 'conversion_factor' => 1, 'cover_price' => null, 'base_price' => 5, 'base_price_percent' => null, 'moq' => 10, 'on_hand' => 400, ...$fields],
    ]);
});

test('the export row writes yes or no for a boolean field and nothing for an empty one', function (): void {
    ProductFieldDefinition::factory()->boolean()->forKind(CategoryKind::Books)->create(['key' => 'colored']);
    $product = $this->importedProduct();
    $product->update(['custom_fields' => ['colored' => false]]);

    $row = $this->mapper->exportRows($product->refresh()->load(['skus.activeUnits.unit', 'skus.activeUnits.tiers', 'skus.baseUnit']), $this->mapper->lookups())[0];

    expect($row['field:colored'])->toBe('لا')
        ->and($row['field:publisher'])->toBeNull();
});
