<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductSnapshot;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-L the snapshot flattens product and sku fields for the audit diff', function (): void {
    $product = Product::factory()->withSku(['code' => 'PEN-1', 'base_price' => '9.5', 'moq' => 3])->create([
        'name' => 'قلم', 'description' => null, 'custom_fields' => ['brand' => 'Bic'],
    ])->load('sku');

    expect(app(ProductSnapshot::class)->of($product))->toBe([
        'name' => 'قلم',
        'category_id' => $product->category_id,
        'description' => null,
        'status' => 'active',
        'custom_fields' => ['brand' => 'Bic'],
        'sku.code' => 'PEN-1',
        'sku.selling_unit_id' => $product->sku->selling_unit_id,
        'sku.base_unit_id' => $product->sku->base_unit_id,
        'sku.conversion_factor' => 1,
        'sku.cover_price' => null,
        'sku.base_price' => '9.50',
        'sku.base_price_percent' => null,
        'sku.moq' => 3,
        'sku.tiers' => [],
    ]);
});

test('BR-L the snapshot lists the tiers, and leaves pricing out when asked', function (): void {
    $product = Product::factory()->withSku()->create()->load('sku');
    App\Modules\Pricing\Models\SkuPriceTier::factory()->for($product->sku)->percent('20.00')->create(['min_quantity' => 10, 'unit_price' => '80.00']);
    $product->load('sku.tiers');

    $full = app(ProductSnapshot::class)->of($product);
    $withoutPricing = app(ProductSnapshot::class)->of($product, withPricing: false);

    expect($full['sku.tiers'])->toBe([['min_quantity' => 10, 'unit_price' => '80.00', 'discount_percent' => '20.00']])
        ->and($withoutPricing)->not->toHaveKeys(['sku.cover_price', 'sku.base_price', 'sku.base_price_percent', 'sku.moq', 'sku.tiers'])
        ->and($withoutPricing)->toHaveKey('sku.code');
});

test('a product without a sku has no sku keys', function (): void {
    $product = Product::factory()->create()->load('sku');

    expect(app(ProductSnapshot::class)->of($product))->not->toHaveKey('sku.code');
});
