<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ImportRowData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\ProductStatus;
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

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->basics = $this->importBasics();
    $this->mapper = app(CatalogRowMapper::class);
});

/**
 * @param  array<string, string>  $row
 * @return array<string, list<string>>
 */
function mapperErrors(CatalogRowMapper $mapper, array $row, ?Product $existing = null): array
{
    try {
        $mapper->map($row, $mapper->lookups(), $existing);
    } catch (InvalidImportRow $e) {
        return $e->details ?? [];
    }

    return [];
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
        'sku_code', 'product_name', 'category_path', 'status', 'description', 'selling_unit', 'base_unit', 'conversion_factor',
        'cover_price', 'base_price', 'base_price_percent', 'moq',
        'tier_1_min', 'tier_1_price', 'tier_2_min', 'tier_2_price', 'tier_3_min', 'tier_3_price', 'tier_4_min', 'tier_4_price', 'tier_5_min', 'tier_5_price',
        'on_hand', 'field:publisher', 'field:brand',
    ])->and($columns['sku_code'])->toBe('كود الصنف')
        ->and($columns['tier_2_price'])->toBe('شريحة 2: السعر')
        ->and($columns['field:publisher'])->toBe('الشركة');
});

test('a sku with more than five tiers gets a tier column pair for each', function (): void {
    $product = $this->importedProduct();
    foreach ([20, 30, 40, 50, 60] as $min) {
        SkuPriceTier::factory()->create(['sku_id' => $product->sku?->id, 'min_quantity' => $min, 'unit_price' => '80.00']);
    }

    expect($this->mapper->lookups()->tierColumns)->toBe(6)
        ->and($this->mapper->columns($this->mapper->lookups()))->toHaveKey('tier_6_price');
});

test('the key row under an arabic title row names the columns and data starts after it', function (): void {
    $rows = mapperRows($this->mapper, [
        ['كود الصنف', 'اسم المنتج'],
        ['sku_code', 'product_name', 'category_path', 'selling_unit', 'base_unit', 'conversion_factor', 'base_price'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '1', '10'],
    ]);

    expect($rows)->toBe([3 => [
        'sku_code' => 'A-1', 'product_name' => 'كتاب', 'category_path' => 'كتب خارجية', 'selling_unit' => 'كرتونة',
        'base_unit' => 'قطعة', 'conversion_factor' => '1', 'base_price' => '10',
    ]]);
});

test('a csv with the key row first is read from its second row', function (): void {
    $rows = mapperRows($this->mapper, [
        ['sku_code', 'product_name', 'category_path', 'selling_unit', 'base_unit', 'conversion_factor', 'base_price'],
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
        'sku_code' => 'A-1', 'product_name' => 'كتاب', 'category_path' => 'كتب خارجية', 'selling_unit' => 'كرتونة',
        'base_unit' => 'قطعة', 'conversion_factor' => '1', 'base_price' => '10', 'field:publisher' => 'الأضواء',
    ]);
});

test('an unknown column is ignored', function (): void {
    $rows = mapperRows($this->mapper, [
        ['sku_code', 'product_name', 'category_path', 'selling_unit', 'base_unit', 'conversion_factor', 'base_price', 'notes', 'field:nothing'],
        ['A-1', 'كتاب', 'كتب خارجية', 'كرتونة', 'قطعة', '1', '10', 'ملاحظة', 'x'],
    ]);

    expect($rows[2])->not->toHaveKey('notes')
        ->and($rows[2])->not->toHaveKey('field:nothing')
        ->and($this->mapper->map($this->importRow(['field:nothing' => 'x']), $this->mapper->lookups())->product->customFields)->toBe(['publisher' => $this->basics['adwaa']->id]);
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
    'a newer format version' => [[['sku_code']], 'إصدار الملف «products_v2» غير مدعوم، نزّل قالبًا جديدًا', 'products_v2'],
]);

test('a key row without data rows yields nothing', function (): void {
    expect(mapperRows($this->mapper, [['sku_code', 'product_name', 'category_path', 'selling_unit', 'base_unit', 'conversion_factor', 'base_price']], 'Sheet1'))->toBe([]);
});

test('a valid row becomes the product data the actions take', function (): void {
    $data = $this->mapper->map($this->importRow(), $this->mapper->lookups());

    expect($data)->toBeInstanceOf(ImportRowData::class)
        ->and($data->product->name)->toBe('الأضواء علوم أولى إعدادي')
        ->and($data->product->categoryId)->toBe($this->basics['grade']->id)
        ->and($data->product->description)->toBe('كتاب العلوم')
        ->and($data->product->customFields)->toBe(['publisher' => $this->basics['adwaa']->id])
        ->and($data->product->sku->code)->toBe('ADW-SCI-1')
        ->and($data->product->sku->sellingUnitId)->toBe($this->basics['carton']->id)
        ->and($data->product->sku->baseUnitId)->toBe($this->basics['piece']->id)
        ->and($data->product->sku->conversionFactor)->toBe(20)
        ->and($data->product->sku->pricing->coverPrice)->toBe('120.00')
        ->and($data->product->sku->pricing->basePrice)->toBe('90.00')
        ->and($data->product->sku->pricing->moq)->toBe(5)
        ->and($data->product->sku->pricing->tiers)->toEqual([new TierData(10, '85.00')])
        ->and($data->product->status)->toBe(ProductStatus::Active)
        ->and($data->product->openingStock)->toBe(400)
        ->and($data->status)->toBe(ProductStatus::Active)
        ->and($data->onHand)->toBe(400);
});

test('D25 the category is found by its full path, nested, whatever the spacing', function (string $path): void {
    expect($this->mapper->map($this->importRow(['category_path' => $path]), $this->mapper->lookups())->product->categoryId)->toBe($this->basics['grade']->id);
})->with([
    'as exported' => ['كتب خارجية > أولى إعدادي'],
    'tight' => ['كتب خارجية>أولى إعدادي'],
    'loose' => ['  كتب   خارجية  >  أولى إعدادي '],
]);

test('a root category is its own path', function (): void {
    expect($this->mapper->map($this->importRow(['category_path' => 'كتب خارجية']), $this->mapper->lookups())->product->categoryId)->toBe($this->basics['root']->id);
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

    expect($this->mapper->map($this->importRow(), $this->mapper->lookups(), $product)->product->categoryId)->toBe($this->basics['grade']->id);
});

test('BR-U01 units are found by name; an unknown or archived unit is a row error', function (): void {
    Unit::factory()->archived()->create(['name' => 'دستة']);

    expect(mapperErrors($this->mapper, $this->importRow(['selling_unit' => 'علبة', 'base_unit' => 'دستة'])))->toBe([
        'selling_unit' => ['الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات'],
        'base_unit' => ['الوحدة مؤرشفة'],
    ]);
});

test('BR-P08 a select field is set by its option label', function (): void {
    expect($this->mapper->map($this->importRow(['field:publisher' => 'المعلم']), $this->mapper->lookups())->product->customFields)
        ->toBe(['publisher' => $this->basics['moallem']->id]);
});

test('BR-P08 an option label the field does not have is a row error', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['field:publisher' => 'سلاح التلميذ'])))->toBe(['field:publisher' => ['الاختيار غير موجود في هذا الحقل']]);
});

test('BR-P08 boolean and number fields read yes/no words and numbers', function (): void {
    ProductFieldDefinition::factory()->boolean()->create(['key' => 'colored']);
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);

    $fields = $this->mapper->map($this->importRow(['field:colored' => 'نعم', 'field:pages' => '120']), $this->mapper->lookups())->product->customFields;

    expect($fields)->toMatchArray(['colored' => true, 'pages' => 120]);
});

test('D69 a value for a field of another section is rejected', function (): void {
    expect(mapperErrors($this->mapper, $this->importRow(['field:brand' => 'فابر كاستل'])))->toBe(['field:brand' => ['هذا الحقل لا ينطبق على قسم هذا المنتج']]);
});

test('D69 a field of another section may repeat the stored value, and an empty cell keeps it', function (): void {
    $product = $this->importedProduct();
    $product->update(['custom_fields' => ['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم']]);

    $repeated = $this->mapper->map($this->importRow(['field:brand' => 'قديم']), $this->mapper->lookups(), $product->refresh());
    $empty = $this->mapper->map($this->importRow(['field:brand' => '']), $this->mapper->lookups(), $product);

    expect($repeated->product->customFields)->toEqual(['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم'])
        ->and($empty->product->customFields)->toEqual(['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم']);
});

test('an empty cell clears a field of the product\'s own section', function (): void {
    expect($this->mapper->map($this->importRow(['field:publisher' => '']), $this->mapper->lookups(), $this->importedProduct())->product->customFields)->toBe([]);
});

test('BR-PR03 tier pairs become tiers, empty pairs are left out, no tier column keeps the tiers', function (): void {
    $lookups = $this->mapper->lookups();

    $two = $this->mapper->map($this->importRow(['tier_2_min' => '50', 'tier_2_price' => '80']), $lookups);
    $none = $this->mapper->map($this->importRow(['tier_1_min' => '', 'tier_1_price' => '']), $lookups);
    $kept = $this->mapper->map($this->importRow(['tier_1_min' => null, 'tier_1_price' => null, 'tier_2_min' => null, 'tier_2_price' => null]), $lookups);

    expect($two->product->sku->pricing->tiers)->toEqual([new TierData(10, '85.00'), new TierData(50, '80.00')])
        ->and($none->product->sku->pricing->tiers)->toBe([])
        ->and($kept->product->sku->pricing->tiers)->toBeNull();
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
    expect($this->mapper->map($this->importRow(['status' => $cell]), $this->mapper->lookups())->status)->toBe($status);
})->with([
    'label' => ['قريبًا', ProductStatus::ComingSoon],
    'value' => ['inactive', ProductStatus::Inactive],
]);

test('an empty status and stock leave them alone', function (): void {
    $data = $this->mapper->map($this->importRow(['status' => '', 'on_hand' => '']), $this->mapper->lookups());

    expect($data->status)->toBeNull()
        ->and($data->onHand)->toBeNull()
        ->and($data->product->openingStock)->toBe(0);
});

test('optional columns left out of the file keep what the product has', function (): void {
    $product = $this->importedProduct(['moq' => 7]);

    $data = $this->mapper->map(
        $this->importRow(['description' => null, 'cover_price' => null, 'moq' => null, 'field:publisher' => null]),
        $this->mapper->lookups(),
        $product,
    );

    expect($data->product->description)->toBe('كتاب العلوم')
        ->and($data->product->sku->pricing->coverPrice)->toBe('120.00')
        ->and($data->product->sku->pricing->moq)->toBe(7)
        ->and($data->product->customFields)->toBe(['publisher' => $this->basics['adwaa']->id]);
});

test('an empty description or reference price clears it', function (): void {
    $data = $this->mapper->map($this->importRow(['description' => '', 'cover_price' => '']), $this->mapper->lookups(), $this->importedProduct());

    expect($data->product->description)->toBeNull()
        ->and($data->product->sku->pricing->coverPrice)->toBeNull();
});

test('BR-PR10 a percentage wins over the price', function (): void {
    $pricing = $this->mapper->map($this->importRow(['base_price' => '95', 'base_price_percent' => '25']), $this->mapper->lookups())->product->sku->pricing;

    expect($pricing->basePrice)->toBeNull()
        ->and($pricing->basePricePercent)->toBe('25.00');
});

test('D95 a row repeating the stored price and percentage keeps both, and a tier keeps its percentage', function (): void {
    $product = $this->importedProduct(['base_price' => '88.00', 'base_price_percent' => '25.00']);
    $product->sku?->tiers()->update(['discount_percent' => '30.00']);

    $pricing = $this->mapper->map(
        $this->importRow(['base_price' => '88', 'base_price_percent' => '25']),
        $this->mapper->lookups(),
        $product->load('sku.tiers'),
    )->product->sku->pricing;

    expect($pricing->basePrice)->toBe('88.00')
        ->and($pricing->basePricePercent)->toBe('25.00')
        ->and($pricing->tiers)->toEqual([new TierData(10, '85.00', '30.00')]);
});

test('errors of the actions point at the column they are about', function (Closure $exception, array $errors): void {
    expect($this->mapper->errors($exception(), $this->mapper->lookups()))->toBe($errors);
})->with([
    'custom field' => [fn (): InvalidCustomFields => InvalidCustomFields::withErrors(['publisher' => 'هذا الحقل مطلوب']), [['column' => 'field:publisher', 'label' => 'الشركة', 'message' => 'هذا الحقل مطلوب']]],
    'D28 tiers' => [fn (): InvalidTiers => InvalidTiers::withProblems(['سعر شريحة 10 أعلى من السعر الأساسي']), [['column' => 'tiers', 'label' => 'شرائح السعر', 'message' => 'سعر شريحة 10 أعلى من السعر الأساسي']]],
    'duplicate code' => [CatalogConflict::duplicateSkuCode(...), [['column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'كود الصنف مستخدم بالفعل']]],
    'BR-INV08 below reserved' => [fn (): BelowReserved => BelowReserved::for(5, 20, []), [['column' => 'on_hand', 'label' => 'المخزون (بالوحدة الأساسية)', 'message' => 'لا يمكن تقليل المخزون إلى 5 لأن 20 وحدة محجوزة لطلبات قائمة. ألغِ الطلبات أو سلّمها أولًا.']]],
    'BR-P04 transition' => [fn (): InvalidProductTransition => InvalidProductTransition::from(ProductStatus::Active, ProductStatus::ComingSoon), [['column' => 'status', 'label' => 'الحالة', 'message' => 'لا يمكن تغيير حالة المنتج من «نشط» إلى «قريبًا»']]],
    'row' => [fn (): InvalidImportRow => InvalidImportRow::withErrors(['tier_3_min' => ['خطأ']]), [['column' => 'tier_3_min', 'label' => 'شريحة 3: من كمية', 'message' => 'خطأ']]],
    'anything else' => [fn (): App\Support\ApiException => App\Support\ApiException::conflict('other', 'رسالة'), [['column' => 'row', 'label' => 'الصف', 'message' => 'رسالة']]],
]);

test('an unknown column has its key as label', function (): void {
    expect($this->mapper->label('notes', $this->mapper->lookups()))->toBe('notes');
});

test('the export row holds the product as the import reads it', function (): void {
    $product = $this->importedProduct(['base_price_percent' => '25.00']);
    $product->update(['custom_fields' => ['publisher' => $this->basics['adwaa']->id, 'brand' => 'قديم']]);
    $product = Product::query()->with(['sku.sellingUnit', 'sku.baseUnit', 'sku.tiers', 'sku.inventory'])->findOrFail($product->id);

    expect($this->mapper->exportRow($product, $this->mapper->lookups()))->toBe([
        'sku_code' => 'ADW-SCI-1',
        'product_name' => 'الأضواء علوم أولى إعدادي',
        'category_path' => 'كتب خارجية > أولى إعدادي',
        'status' => 'نشط',
        'description' => 'كتاب العلوم',
        'selling_unit' => 'كرتونة',
        'base_unit' => 'قطعة',
        'conversion_factor' => 20,
        'cover_price' => 120,
        'base_price' => 90,
        'base_price_percent' => 25,
        'moq' => 5,
        'on_hand' => 400,
        'tier_1_min' => 10,
        'tier_1_price' => 85,
        'field:publisher' => 'الأضواء',
        'field:brand' => 'قديم',
    ]);
});

test('the export row writes yes or no for a boolean field and nothing for an empty one', function (): void {
    ProductFieldDefinition::factory()->boolean()->forKind(CategoryKind::Books)->create(['key' => 'colored']);
    $product = $this->importedProduct();
    $product->update(['custom_fields' => ['colored' => false]]);

    $row = $this->mapper->exportRow($product->refresh()->load('sku.tiers'), $this->mapper->lookups());

    expect($row['field:colored'])->toBe('لا')
        ->and($row['field:publisher'])->toBeNull();
});
