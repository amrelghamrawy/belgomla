<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Exceptions\PricesHidden;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\PriceListExporter;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P13 the price list file holds a row per visible product with the price list columns', function (): void {
    Product::factory()->withSku(['code' => 'A-1', 'base_price' => '12.50'])->create(['name' => 'كراسة']);
    $path = sys_get_temp_dir().'/price-list-'.Str::uuid().'.xlsx';

    app(PriceListExporter::class)->write($path, new ProductFilterData);

    expect(spreadsheetRows($path))->toHaveCount(2)
        ->and(spreadsheetRows($path)[1])->toBe(array_values(PriceListExporter::COLUMNS))
        ->and(spreadsheetRows($path)[2][2])->toBe('A-1')
        ->and(spreadsheetRows($path)[2][6])->toBe('12.5');
});

test('BR-P13 D91 a product sold in two units has a row per unit, the default first', function (): void {
    $product = Product::factory()->withSku(['code' => 'A-1', 'base_price' => '12.50'])->create(['name' => 'كراسة']);
    $carton = $this->addUnit($product->sku()->sole(), ['base_price' => '140.00']);
    $path = sys_get_temp_dir().'/price-list-'.Str::uuid().'.xlsx';

    app(PriceListExporter::class)->write($path, new ProductFilterData);

    expect(spreadsheetRows($path))->toHaveCount(3)
        ->and(array_map(fn (array $row): array => [$row[2], $row[3], $row[6]], array_slice(spreadsheetRows($path), 1)))->toBe([
            ['A-1', $product->sku()->sole()->defaultUnit?->unit->name, '12.5'],
            ['A-1', $carton->unit->name, '140'],
        ]);
});

test('D1 the writer refuses a guest of a store that hides prices before anything is streamed', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);

    expect(fn () => app(PriceListExporter::class)->writer(new ProductFilterData, null))->toThrow(PricesHidden::class);
});

test('the writer streams the file to the response', function (): void {
    $writer = app(PriceListExporter::class)->writer(new ProductFilterData, null);

    ob_start();
    $writer();
    $content = (string) ob_get_clean();

    expect(mb_substr($content, 0, 2))->toBe('PK');
});

test('BR-PR14 the file has the customer\'s own wholesale price', function (): void {
    $sku = Product::factory()->withSku(['code' => 'A-1', 'base_price' => '12.50'])->create(['name' => 'كراسة'])->sku()->sole();
    $path = sys_get_temp_dir().'/price-list-'.Str::uuid().'.xlsx';

    $customer = $this->makeCustomer();
    App\Modules\Customers\Models\CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => $sku->id, 'unit_price' => '11.00']);

    app(PriceListExporter::class)->write($path, new ProductFilterData, $customer);

    expect(spreadsheetRows($path)[2][6])->toBe('11');
});
