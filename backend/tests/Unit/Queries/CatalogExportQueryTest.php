<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\PreviewCatalogImport;
use App\Modules\Catalog\Actions\RunCatalogImport;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Jobs\ImportCatalog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Queries\CatalogExportQuery;
use App\Modules\Catalog\Queries\PriceListExportQuery;
use App\Modules\Catalog\Services\CatalogExporter;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Inventory\Models\InventoryMovement;
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
 * Every product as a flat comparable snapshot: product, SKU, tiers, stock.
 *
 * @return array<int, array<string, mixed>>
 */
function exportSnapshot(): array
{
    return Product::query()->with(['sku.tiers', 'sku.inventory'])->orderBy('id')->get()->mapWithKeys(fn (Product $product): array => [$product->id => [
        ...$product->only(['name', 'category_id', 'description', 'status', 'slug']),
        'custom_fields' => collect($product->custom_fields)->sortKeys()->all(),
        'sku' => $product->sku?->only(['code', 'selling_unit_id', 'base_unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq']),
        'tiers' => $product->sku?->tiers->sortBy('min_quantity')->map(fn (SkuPriceTier $tier): array => $tier->only(['min_quantity', 'unit_price', 'discount_percent']))->values()->all(),
        'on_hand' => $product->sku?->inventory?->on_hand,
    ]])->all();
}

test('the export reads every product of the filter with its sku, units, tiers and stock loaded', function (): void {
    $kept = $this->importedProduct();
    $other = Product::factory()->withSku()->create(['category_id' => Category::factory()->create()->id]);

    $rows = app(CatalogExportQuery::class)->get(new ProductFilterData(categoryId: $this->basics['root']->id))->all();

    expect(array_map(fn (Product $product): int => $product->id, $rows))->toBe([$kept->id])
        ->and($rows[0]->relationLoaded('sku'))->toBeTrue()
        ->and($rows[0]->sku?->relationLoaded('tiers'))->toBeTrue()
        ->and($rows[0]->sku?->relationLoaded('inventory'))->toBeTrue()
        ->and($rows[0]->sku?->relationLoaded('sellingUnit'))->toBeTrue()
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

    expect(app(App\Modules\Catalog\Services\Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Xlsx))->toBe('products_v1')
        ->and($rows[1][0])->toBe('كود الصنف')
        ->and($rows[2])->toBe(array_keys(app(CatalogRowMapper::class)->columns(app(CatalogRowMapper::class)->lookups())))
        ->and(array_slice($rows[3], 0, 13))->toBe(['ADW-SCI-1', 'الأضواء علوم أولى إعدادي', 'كتب خارجية > أولى إعدادي', 'نشط', 'كتاب العلوم', 'كرتونة', 'قطعة', '20', '120', '90', '', '5', '10'])
        ->and(count($rows))->toBe(3);
});

test('the export follows the dashboard filter', function (): void {
    $this->importedProduct();
    Product::factory()->withSku(['code' => 'OTHER-1'])->create();
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.csv';

    app(CatalogExporter::class)->write($path, new ProductFilterData(q: 'الأضواء'), SpreadsheetFormat::Csv);

    expect(array_column(spreadsheetRows($path, SpreadsheetFormat::Csv), 0))->toBe(['sku_code', 'ADW-SCI-1']);
});

test('export then import of the same file is an update of every row that changes nothing', function (SpreadsheetFormat $format): void {
    Queue::fake();
    ProductFieldDefinition::factory()->boolean()->forKind(CategoryKind::Books)->create(['key' => 'colored', 'label' => 'ملون']);
    $percent = $this->importedProduct(['code' => 'PCT-1', 'base_price' => '88.00', 'base_price_percent' => '25.00']);
    $percent->update(['custom_fields' => ['publisher' => $this->basics['moallem']->id, 'colored' => true, 'brand' => 'قديم']]);
    SkuPriceTier::query()->where('sku_id', $percent->sku?->id)->update(['discount_percent' => '30.00']);
    $this->importedProduct(['code' => 'PLAIN-1'], onHand: 0, status: ProductStatus::ComingSoon);
    Product::factory()->withSku(['code' => 'NO-STOCK-ROW', 'cover_price' => null])->inactive()->create(['category_id' => $this->basics['root']->id, 'custom_fields' => []]);
    $before = exportSnapshot();
    $audits = AuditLog::query()->count();
    $path = sys_get_temp_dir().'/export-'.Str::uuid().'.'.$format->value;
    app(CatalogExporter::class)->write($path, new ProductFilterData, $format);

    $preview = app(PreviewCatalogImport::class)->handle(new UploadedFile($path, 'products.'.$format->value, null, null, true));
    $import = app(RunCatalogImport::class)->handle($preview->token);
    runJob(new ImportCatalog($import->id));

    expect($preview->errors)->toBe([])
        ->and([$preview->total, $preview->update, $preview->create, $preview->skip])->toBe([3, 3, 0, 0])
        ->and($import->refresh()->only(['updated', 'created', 'skipped', 'failed']))->toBe(['updated' => 3, 'created' => 0, 'skipped' => 0, 'failed' => 0])
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
