<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Unit;

/*
| GET /api/store/price-list/export (BR-P13, D83, V2 Task 06): the price list of a selection as an Excel file.
*/

test('BR-P13 the price list file has the visible products of the selection by name, with their prices', function (): void {
    $this->inTenant(function (): void {
        $carton = Unit::factory()->create(['name' => 'كرتونة']);
        $books = Category::factory()->inSection('books')->create();
        Product::factory()->withSku(['code' => 'B-1', 'cover_price' => '120', 'base_price' => '90', 'moq' => 5, 'selling_unit_id' => $carton->id])->create(['name' => 'ب كتاب', 'category_id' => $books->id]);
        Product::factory()->withSku(['code' => 'A-1', 'base_price' => '7.58', 'selling_unit_id' => $carton->id])->comingSoon()->create(['name' => 'أ قلم']);
        Product::factory()->withSku()->inactive()->create(['name' => 'ج مخفي']);
    });

    $response = $this->tenantJson('GET', '/api/store/price-list/export');

    $response->assertOk()
        ->assertHeader('content-type', SpreadsheetFormat::Xlsx->mimeType())
        ->assertDownload('price-list-'.now()->format('Y-m-d').'.xlsx');
    expect(spreadsheetRows(downloadedFile($response)))->toBe([
        1 => ['المنتج', 'القسم', 'الكود', 'الوحدة', 'الحد الأدنى للطلب', 'السعر المرجعي (الغلاف أو القطاعي)', 'سعر الجملة', 'التوفر'],
        2 => ['أ قلم', 'عام', 'A-1', 'كرتونة', '1', '', '7.58', 'قريبًا'],
        3 => ['ب كتاب', 'كتب خارجية', 'B-1', 'كرتونة', '5', '120', '90', 'متاح'],
    ]);
});

test('BR-P13 the price list file follows the facet, category and search selection', function (string $query): void {
    $this->inTenant(function (): void {
        $publisher = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'publisher']);
        $adwaa = ProductFieldOption::factory()->for($publisher, 'definition')->create(['label' => 'الأضواء']);
        $grade = Category::factory()->create(['name' => 'أولى إعدادي', 'slug' => 'oula']);
        Product::factory()->withSku(['code' => 'MATCH'])->create(['name' => 'الأضواء علوم', 'category_id' => $grade->id, 'custom_fields' => ['publisher' => $adwaa->id]]);
        Product::factory()->withSku(['code' => 'OTHER'])->create(['name' => 'كراسة']);
    });
    $option = $this->inTenant(fn (): int => ProductFieldOption::query()->sole()->id);

    $response = $this->tenantJson('GET', '/api/store/price-list/export?'.str_replace('{option}', (string) $option, $query));

    expect(array_column(spreadsheetRows(downloadedFile($response)), 2))->toBe(['الكود', 'MATCH']);
})->with([
    'facet' => ['f[publisher]={option}'],
    'category' => ['category=oula'],
    'search' => ['q=علوم'],
]);

test('D1 a guest gets no price list file when the store hides prices', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);

    $this->tenantJson('GET', '/api/store/price-list/export')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'prices_hidden')
        ->assertJsonPath('error.message', 'سجّل الدخول لعرض الأسعار والإضافة إلى السلة');
});

test('D1 a logged-in customer gets the file when the store hides prices from guests', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);
    $this->actingAsCustomer();

    $this->tenantJson('GET', '/api/store/price-list/export')->assertOk()->assertDownload();
});

test('the price list file validates its query', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/store/price-list/export?{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'D83 csv is the catalog format, not the price list one' => ['format=csv', 'format'],
    'status is not a store filter' => ['status=inactive', 'status'],
]);
