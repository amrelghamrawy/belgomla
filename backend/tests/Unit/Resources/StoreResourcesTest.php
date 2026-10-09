<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Resources\FacetResource;
use App\Modules\Catalog\Http\Resources\PriceListRowResource;
use App\Modules\Catalog\Http\Resources\StoreProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Queries\StorefrontFieldsQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the store product resource key set with visible prices', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['category', 'sku.sellingUnit', 'mainImage']);

    $data = rendered(new StoreProductResource($product, new Collection, false), storeRequest());

    expect(array_keys($data))->toBe(['id', 'name', 'slug', 'description', 'status', 'main_image_url', 'category', 'custom_fields', 'sku'])
        ->and(array_keys($data['sku']))->toBe(['id', 'code', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'in_stock'])
        ->and($data['sku']['unit'])->toBe($product->sku->sellingUnit->name);
});

test('D1 hidden prices drop the price keys and flag the product', function (): void {
    $product = Product::factory()->withSku()->create()->load('sku');

    $data = rendered(new StoreProductResource($product, new Collection, true), storeRequest());

    expect($data['prices_hidden'])->toBeTrue()
        ->and($data['sku'])->not->toHaveKeys(['cover_price', 'base_price']);
});

test('BR-P04 a coming soon product is flagged and not in stock', function (): void {
    $product = Product::factory()->withSku()->comingSoon()->create()->load('sku');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['in_stock'])->toBeFalse()
        ->and($sku['coming_soon'])->toBeTrue();
});

test('BR-P08 custom fields render per type with labels, unknown option ids are skipped', function (): void {
    $grade = ProductFieldDefinition::factory()->select()->create(['key' => 'grade', 'label' => 'السنة', 'sort_order' => 1]);
    $first = ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى']);
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages', 'label' => 'صفحات', 'sort_order' => 2]);
    ProductFieldDefinition::factory()->boolean()->create(['key' => 'colored', 'label' => 'ملون', 'sort_order' => 3]);
    ProductFieldDefinition::factory()->select()->create(['key' => 'publisher', 'sort_order' => 4]);
    $product = Product::factory()->create(['custom_fields' => [
        'grade' => $first->id, 'pages' => 96, 'colored' => false, 'publisher' => 999_999,
    ]]);

    $data = rendered(new StoreProductResource($product, app(StorefrontFieldsQuery::class)->get(), false), storeRequest());

    expect($data['custom_fields'])->toBe([
        ['key' => 'grade', 'label' => 'السنة', 'value' => 'أولى'],
        ['key' => 'pages', 'label' => 'صفحات', 'value' => '96'],
        ['key' => 'colored', 'label' => 'ملون', 'value' => 'لا'],
    ]);
});

test('a product without loaded relations renders nulls instead of lazy-loading', function (): void {
    $product = Product::factory()->withSku()->create()->refresh();

    $data = rendered(new StoreProductResource($product, new Collection, false), storeRequest());

    expect($data['category'])->toBeNull()
        ->and($data['sku'])->toBeNull()
        ->and($data['main_image_url'])->toBeNull();
});

test('the facet resource key set', function (): void {
    $facet = ['key' => 'grade', 'label' => 'السنة', 'type' => 'select', 'options' => [['id' => 1, 'label' => 'أولى', 'count' => 3]]];

    expect(rendered(new FacetResource($facet), storeRequest()))->toBe($facet);
});

test('BR-P13 the price list row key set, with and without prices', function (): void {
    $product = Product::factory()->withSku(['code' => 'A-1', 'cover_price' => '10', 'base_price' => '8'])->create()->load('sku.sellingUnit');

    $visible = rendered(new PriceListRowResource($product, false), storeRequest());
    $hidden = rendered(new PriceListRowResource($product, true), storeRequest());

    expect(array_keys($visible))->toBe(['id', 'name', 'slug', 'code', 'unit', 'moq', 'cover_price', 'base_price', 'availability'])
        ->and($visible['availability'])->toBe('available')
        ->and($hidden)->not->toHaveKeys(['cover_price', 'base_price']);
});
