<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Actions\PreviewCatalogImport;
use App\Modules\Catalog\Actions\RunCatalogImport;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Jobs\ImportCatalog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Queries\CatalogExportQuery;
use App\Modules\Catalog\Queries\PriceListExportQuery;
use App\Modules\Catalog\Services\CatalogExporter;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->basics = $this->importBasics();
});

/**
 * Every product as a flat comparable snapshot: product, options and values (BR-P14), every SKU with its status, option
 * values and stock, every unit with its tiers (D91).
 *
 * @return array<int, array<string, mixed>>
 */
function exportSnapshot(): array
{
    return Product::query()->with(['options.values', 'skus.units.tiers', 'skus.inventory', 'skus.optionValues'])->orderBy('id')->get()->mapWithKeys(fn (Product $product): array => [$product->id => [
        ...$product->only(['name', 'category_id', 'description', 'status', 'slug']),
        'custom_fields' => collect($product->custom_fields)->sortKeys()->all(),
        'options' => $product->options->map(fn (ProductOption $option): array => [...$option->only(['id', 'name', 'sort_order']), 'values' => $option->values->map(fn (ProductOptionValue $value): array => $value->only(['id', 'value', 'sort_order', 'image_id']))->all()])->all(),
        'skus' => $product->skus->map(fn (Sku $sku): array => [
            ...$sku->only(['id', 'code', 'name', 'base_unit_id', 'sort_order']),
            'status' => $sku->status->value,
            'option_values' => $sku->optionValues->pluck('id')->sort()->values()->all(),
            'on_hand' => $sku->inventory?->on_hand,
            'units' => $sku->units->map(fn (SkuUnit $unit): array => [
                ...$unit->only(['id', 'unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq', 'is_default', 'sort_order']),
                'status' => $unit->status->value,
                'tiers' => $unit->tiers->sortBy('min_quantity')->map(fn (SkuPriceTier $tier): array => $tier->only(['min_quantity', 'unit_price', 'discount_percent']))->values()->all(),
            ])->all(),
        ])->all(),
    ]])->all();
}

/**
 * BR-P14: a pen in two colours of one size, sold by the piece: PEN-BLUE active with 100 in stock, PEN-RED inactive with 50.
 *
 * @param  array<string, mixed>  $basics  TenantTestCase::importBasics()
 */
function exportVariantPen(array $basics): Product
{
    $grade = $basics['grade'];
    $piece = $basics['piece'];
    $adwaa = $basics['adwaa'];
    assert($grade instanceof Category && $piece instanceof Unit && $adwaa instanceof ProductFieldOption);

    return app(CreateProduct::class)->handle(new ProductData(
        name: 'قلم جاف',
        categoryId: $grade->id,
        skus: [
            new SkuData('PEN-BLUE', $piece->id, [new SkuUnitData(null, $piece->id, 1, true, new SkuPricingData(basePrice: '5.00', moq: 10))], optionValues: ['أزرق', 'M'], openingStock: 100, path: 'skus.0'),
            new SkuData('PEN-RED', $piece->id, [new SkuUnitData(null, $piece->id, 1, true, new SkuPricingData(basePrice: '5.50', moq: 10))], status: SkuStatus::Inactive, optionValues: ['أحمر', 'M'], openingStock: 50, path: 'skus.1'),
        ],
        options: [
            new ProductOptionData(null, 'اللون', [new ProductOptionValueData(null, 'أزرق'), new ProductOptionValueData(null, 'أحمر')]),
            new ProductOptionData(null, 'المقاس', [new ProductOptionValueData(null, 'M')]),
        ],
        customFields: ['publisher' => $adwaa->id],
    ));
}

test('the export reads every product of the filter with its options and every sku\'s units, tiers, stock and option values loaded', function (): void {
    $kept = $this->importedProduct();
    $other = Product::factory()->withSku()->create(['category_id' => Category::factory()->create()->id]);

    $rows = app(CatalogExportQuery::class)->get(new ProductFilterData(categoryId: $this->basics['root']->id))->all();

    expect(array_map(fn (Product $product): int => $product->id, $rows))->toBe([$kept->id])
        ->and($rows[0]->relationLoaded('skus'))->toBeTrue()
        ->and($rows[0]->relationLoaded('options'))->toBeTrue()
        ->and($rows[0]->skus->first()?->relationLoaded('activeUnits'))->toBeTrue()
        ->and($rows[0]->skus->first()?->relationLoaded('optionValues'))->toBeTrue()
        ->and($rows[0]->skus->first()?->activeUnits->first()?->relationLoaded('tiers'))->toBeTrue()
        ->and($rows[0]->skus->first()?->activeUnits->first()?->relationLoaded('unit'))->toBeTrue()
        ->and($rows[0]->skus->first()?->relationLoaded('inventory'))->toBeTrue()
        ->and($rows[0]->skus->first()?->relationLoaded('baseUnit'))->toBeTrue()
        ->and(app(CatalogExportQuery::class)->count(new ProductFilterData))->toBe(2)
        ->and($other->exists)->toBeTrue();
});

test('the export includes every status, oldest product first, and follows the status filter', function (): void {
    $first = Product::factory()->withSku()->inactive()->create();
    $second = Product::factory()->withSku()->create();

    $all = app(CatalogExportQuery::class)->get(new ProductFilterData)->map(fn (Product $product): int => $product->id)->all();
    $inactive = app(CatalogExportQuery::class)->get(new ProductFilterData(status: ProductStatus::Inactive))->map(fn (Product $product): int => $product->id)->all();

    expect($all)->toBe([$first->id, $second->id])
        ->and($inactive)->toBe([$first->id]);
});

test('the exported file has the format version, the arabic labels, the key row and a row per product', function (): void {
    $this->importedProduct();
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.xlsx';

    app(CatalogExporter::class)->write($path, new ProductFilterData, SpreadsheetFormat::Xlsx);
    $rows = spreadsheetRows($path);

    expect(app(App\Modules\Catalog\Services\Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Xlsx))->toBe('products_v3')
        ->and($rows[1][1])->toBe('كود الصنف')
        ->and($rows[2])->toBe(array_keys(app(CatalogRowMapper::class)->columns(app(CatalogRowMapper::class)->lookups())))
        ->and(array_slice($rows[3], 0, 19))->toBe(['ADW-SCI-1', 'ADW-SCI-1', 'الأضواء علوم أولى إعدادي', 'كتب خارجية > أولى إعدادي', 'نشط', 'كتاب العلوم', '', '', '', '', '', 'قطعة', 'كرتونة', 'نعم', '20', '120', '90', '', '5'])
        ->and(count($rows))->toBe(3);
});

test('D91 the exported file has a row per active unit of a SKU, the default first', function (): void {
    $product = $this->importedProduct();
    $this->addUnit($product->skus->first(), ['unit_id' => $this->basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    $this->addUnit($product->skus->first(), ['unit_id' => Unit::factory()->create(['name' => 'علبة'])->id])->update(['status' => 'archived']);
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.csv';

    app(CatalogExporter::class)->write($path, new ProductFilterData, SpreadsheetFormat::Csv);
    $rows = spreadsheetRows($path, SpreadsheetFormat::Csv);

    expect(array_map(fn (array $row): array => [...array_slice($row, 1, 5), ...array_slice($row, 11, 4)], array_slice($rows, 1)))->toBe([
        ['ADW-SCI-1', 'الأضواء علوم أولى إعدادي', 'كتب خارجية > أولى إعدادي', 'نشط', 'كتاب العلوم', 'قطعة', 'كرتونة', 'نعم', '20'],
        ['ADW-SCI-1', 'الأضواء علوم أولى إعدادي', 'كتب خارجية > أولى إعدادي', 'نشط', 'كتاب العلوم', 'قطعة', 'قطعة', 'لا', '1'],
    ]);
});

test('BR-P14 a product with variants exports a block of rows per SKU under its first code as key, inactive variants included', function (): void {
    exportVariantPen($this->basics);
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.csv';

    app(CatalogExporter::class)->write($path, new ProductFilterData, SpreadsheetFormat::Csv);
    $rows = spreadsheetRows($path, SpreadsheetFormat::Csv);
    $keys = ['product_key', 'sku_code', 'product_name', 'variant_option_1', 'variant_value_1', 'variant_option_2', 'variant_value_2', 'sku_status', 'unit', 'base_price', 'on_hand'];

    expect(array_map(fn (array $row): array => array_values(array_intersect_key(array_combine($rows[1], $row), array_flip($keys))), array_slice($rows, 1)))->toBe([
        ['PEN-BLUE', 'PEN-BLUE', 'قلم جاف', 'اللون', 'أزرق', 'المقاس', 'M', 'نشط', 'قطعة', '5', '100'],
        ['PEN-BLUE', 'PEN-RED', 'قلم جاف', 'اللون', 'أحمر', 'المقاس', 'M', 'غير نشط', 'قطعة', '5.5', '50'],
    ]);
});

test('the export follows the dashboard filter', function (): void {
    $this->importedProduct();
    Product::factory()->withSku(['code' => 'OTHER-1'])->create();
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.csv';

    app(CatalogExporter::class)->write($path, new ProductFilterData(q: 'الأضواء'), SpreadsheetFormat::Csv);

    expect(array_column(spreadsheetRows($path, SpreadsheetFormat::Csv), 1))->toBe(['sku_code', 'ADW-SCI-1']);
});

test('D91 D95 BR-P14 export then import of the same file is an update of every product that changes nothing, units and variants included', function (SpreadsheetFormat $format): void {
    Queue::fake();
    ProductFieldDefinition::factory()->boolean()->forKind(CategoryKind::Books)->create(['key' => 'colored', 'label' => 'ملون']);
    $percent = $this->importedProduct(['code' => 'PCT-1', 'base_price' => '88.00', 'base_price_percent' => '25.00']);
    $percent->update(['custom_fields' => ['publisher' => $this->basics['moallem']->id, 'colored' => true, 'brand' => 'قديم']]);
    SkuPriceTier::query()->where('sku_unit_id', $this->defaultUnitId($percent->skus->first()))->update(['discount_percent' => '30.00']);
    $this->addUnit($percent->skus->first(), ['unit_id' => $this->basics['piece']->id, 'conversion_factor' => 1, 'cover_price' => '7.00', 'base_price' => '5.25', 'base_price_percent' => '25.00', 'moq' => 10], [50 => '4.90']);
    $this->importedProduct(['code' => 'PLAIN-1'], onHand: 0, status: ProductStatus::ComingSoon);
    Product::factory()->withSku(['code' => 'NO-STOCK-ROW', 'cover_price' => null])->inactive()->create(['category_id' => $this->basics['root']->id, 'custom_fields' => []]);
    exportVariantPen($this->basics);
    $before = exportSnapshot();
    $audits = (int) AuditLog::query()->max('id');
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.'.$format->value;
    app(CatalogExporter::class)->write($path, new ProductFilterData, $format);

    $preview = app(PreviewCatalogImport::class)->handle(new UploadedFile($path, 'products.'.$format->value, null, null, true));
    $import = app(RunCatalogImport::class)->handle($preview->token);
    runJob(new ImportCatalog($import->id));

    expect($preview->errors)->toBe([])
        ->and(count(spreadsheetRows($path, $format)))->toBe($format === SpreadsheetFormat::Xlsx ? 8 : 7)
        ->and([$preview->total, $preview->update, $preview->create, $preview->skip])->toBe([4, 4, 0, 0])
        ->and($import->refresh()->only(['updated', 'created', 'skipped', 'failed']))->toBe(['updated' => 4, 'created' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(exportSnapshot())->toEqual($before)
        ->and(InventoryMovement::query()->where('type', 'adjustment')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('id', '>', $audits)->pluck('action')->all())->toBe(['catalog.import_started', 'catalog.imported']);
})->with(['xlsx' => [SpreadsheetFormat::Xlsx], 'csv' => [SpreadsheetFormat::Csv]]);

test('BR-P13 the price list export reads the visible products of the selection by name, uncapped', function (): void {
    config(['catalog.price_list_limit' => 1]);
    $b = Product::factory()->withSku()->create(['name' => 'ب كتاب']);
    $a = Product::factory()->withSku()->comingSoon()->create(['name' => 'أ كتاب']);
    Product::factory()->withSku()->inactive()->create(['name' => 'ت مخفي']);

    $rows = app(PriceListExportQuery::class)->get(new ProductFilterData)->map(fn (Product $product): int => $product->id)->all();

    expect($rows)->toBe([$a->id, $b->id]);
});
