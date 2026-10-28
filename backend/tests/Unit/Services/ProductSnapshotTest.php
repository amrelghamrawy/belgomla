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
    ])->load('sku.defaultUnit');

    expect(app(ProductSnapshot::class)->of($product))->toBe([
        'name' => 'قلم',
        'category_id' => $product->category_id,
        'description' => null,
        'status' => 'active',
        'custom_fields' => ['brand' => 'Bic'],
        'preorder_enabled' => false,
        'expected_at' => null,
        'preorder_limit' => null,
        'sku.code' => 'PEN-1',
        'sku.base_unit_id' => $product->sku?->base_unit_id,
        'sku.units' => [[
            'unit_id' => $product->sku?->defaultUnit?->unit_id,
            'conversion_factor' => 1,
            'is_default' => true,
            'cover_price' => null,
            'base_price' => '9.50',
            'base_price_percent' => null,
            'moq' => 3,
            'tiers' => [],
        ]],
    ]);
});

test('BR-L D91 the snapshot lists every active unit with its tiers, and leaves pricing out when asked', function (): void {
    $product = Product::factory()->withSku()->create();
    $sku = $product->sku()->sole();
    App\Modules\Pricing\Models\SkuPriceTier::factory()->percent('20.00')->create(['sku_id' => $sku->id, 'min_quantity' => 10, 'unit_price' => '80.00']);
    $carton = $this->addUnit($sku);
    App\Modules\Catalog\Models\SkuUnit::factory()->archived()->create(['sku_id' => $sku->id]);
    $product->load('sku.activeUnits.tiers');

    $full = app(ProductSnapshot::class)->of($product);
    $withoutPricing = app(ProductSnapshot::class)->of($product, withPricing: false);

    expect(array_column($full['sku.units'], 'tiers'))->toBe([[['min_quantity' => 10, 'unit_price' => '80.00', 'discount_percent' => '20.00']], []])
        ->and($withoutPricing['sku.units'])->toBe([
            ['unit_id' => $sku->defaultUnit?->unit_id, 'conversion_factor' => 1, 'is_default' => true],
            ['unit_id' => $carton->unit_id, 'conversion_factor' => 12, 'is_default' => false],
        ]);
});

test('a product without a sku has no sku keys', function (): void {
    $product = Product::factory()->create()->load('sku');

    expect(app(ProductSnapshot::class)->of($product))->not->toHaveKey('sku.code');
});
