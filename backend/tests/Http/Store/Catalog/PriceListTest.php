<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Sku;

test('BR-P13 the price list returns compact rows for the selection, by name, unpaginated', function (): void {
    [$b, $a] = $this->inTenant(fn (): array => [
        Product::factory()->withSku(['code' => 'B-1', 'cover_price' => '120', 'base_price' => '90', 'moq' => 5])->create(['name' => 'ب']),
        Product::factory()->withSku(['code' => 'A-1'])->comingSoon()->create(['name' => 'أ']),
        Product::factory()->withSku()->inactive()->create(['name' => 'ج']),
    ]);

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['*' => ['id', 'sku_id', 'variant', 'sku_unit_id', 'name', 'slug', 'kind', 'code', 'unit', 'conversion_factor', 'is_default', 'moq', 'cover_price', 'base_price', 'availability']],
            'meta' => ['count', 'truncated', 'prices_hidden'],
        ])
        ->assertJsonPath('meta', ['count' => 2, 'truncated' => false, 'prices_hidden' => false])
        ->assertJsonPath('data.0.id', $a->id)
        ->assertJsonPath('data.0.availability', 'coming_soon')
        ->assertJsonPath('data.1.id', $b->id)
        ->assertJsonPath('data.1.code', 'B-1')
        ->assertJsonPath('data.1.cover_price', '120.00')
        ->assertJsonPath('data.1.base_price', '90.00')
        ->assertJsonPath('data.1.moq', 5)
        ->assertJsonPath('data.1.availability', 'available');
});

test('BR-P13 D91 a product sold in two units has a row per unit, the default first', function (): void {
    $product = $this->inTenant(fn (): Product => Product::factory()->withSku(['code' => 'N-1', 'base_price' => '10.00'])->create(['name' => 'كشكول']));
    $carton = $this->addUnit($this->inTenant(fn () => $product->skus()->sole()), ['conversion_factor' => 24, 'base_price' => '220.00']);

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('meta.count', 2)
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.0.base_price', '10.00')
        ->assertJsonPath('data.1.sku_unit_id', $carton->id)
        ->assertJsonPath('data.1.conversion_factor', 24)
        ->assertJsonPath('data.1.base_price', '220.00');
});

test('BR-P13 the price list uses the same facet filters as the product list', function (): void {
    $match = $this->inTenant(function (): Product {
        $publisher = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'publisher']);
        $adwaa = ProductFieldOption::factory()->for($publisher, 'definition')->create();
        Product::factory()->withSku()->create();

        return Product::factory()->withSku()->create(['custom_fields' => ['publisher' => $adwaa->id]]);
    });

    $publisherOption = $match->custom_fields['publisher'];

    $this->tenantJson('GET', "/api/store/price-list?f[publisher]={$publisherOption}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

test('the price list is capped and says so', function (): void {
    config(['catalog.price_list_limit' => 2]);
    $this->inTenant(fn () => Product::factory()->withSku()->count(3)->create());

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.truncated', true)
        ->assertJsonPath('meta.count', 2);
});

test('D1 the price list hides prices when the tenant requires login', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);
    $this->inTenant(fn () => Product::factory()->withSku()->create());

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('meta.prices_hidden', true)
        ->assertJsonMissingPath('data.0.base_price')
        ->assertJsonMissingPath('data.0.cover_price');
});

test('BR-P13 BR-P14 a variant product has a row per unit of each active variant, naming the variant', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود', 'أحمر'], prices: ['أسود' => '11.00']);
    [$blue, $black, $red] = $this->inTenant(fn (): array => Sku::query()->orderBy('code')->get()->all());
    $this->inTenant(fn () => $red->update(['status' => 'inactive']));
    $carton = $this->addUnit($black, ['base_price' => '120.00']);

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('meta.count', 3)
        ->assertJsonPath('data.*.sku_id', [$blue->id, $black->id, $black->id])
        ->assertJsonPath('data.*.variant', ['أزرق', 'أسود', 'أسود'])
        ->assertJsonPath('data.*.code', ['PEN-1', 'PEN-2', 'PEN-2'])
        ->assertJsonPath('data.*.sku_unit_id', [$this->defaultUnitId($blue), $this->defaultUnitId($black), $carton->id])
        ->assertJsonPath('data.*.base_price', ['10.00', '11.00', '120.00']);
});

test('BR-P14 a product without options has rows without a variant', function (): void {
    $sku = $this->inTenant(fn () => Product::factory()->withSku(['code' => 'N-1'])->create()->skus()->sole());

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('data.0.sku_id', $sku->id)
        ->assertJsonPath('data.0.variant', null);
});
