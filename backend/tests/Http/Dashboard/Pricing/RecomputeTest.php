<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Models\SkuPriceTier;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-PR10 recompute applies the stored percentages to the new cover price, audited', function (): void {
    $product = $this->inTenant(function (): Product {
        $product = Product::factory()->withSku(['cover_price' => '140.00', 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create();
        SkuPriceTier::factory()->for($product->sku)->percent('30.00')->create(['min_quantity' => 10, 'unit_price' => '84.00']);

        return $product;
    });

    $this->tenantJson('POST', "/api/dashboard/products/{$product->id}/pricing/recompute")
        ->assertOk()
        ->assertJsonPath('data.base_price', '105.00')
        ->assertJsonPath('data.tiers', [['min_quantity' => 10, 'unit_price' => '98.00', 'discount_percent' => '30.00']]);

    $logs = $this->inTenant(fn () => AuditLog::query()->get());

    expect($logs->pluck('action')->sort()->values()->all())->toBe(['sku.price_changed', 'sku.tiers_changed'])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id]);
});

test('BR-PR10 recompute without a cover price is refused with cover_price_required', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku(['cover_price' => null, 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create());

    $this->tenantJson('POST', "/api/dashboard/products/{$product->id}/pricing/recompute")
        ->assertUnprocessable()
        ->assertExactJson(['error' => ['code' => 'cover_price_required', 'message' => 'أدخل سعر الغلاف أولًا لحساب السعر بنسبة الخصم']]);
});

test('BR-T04 recomputing another tenant product is 404 and changes nothing', function (): void {
    $foreign = $this->inTenant(
        fn () => Product::factory()->withSku(['cover_price' => '140.00', 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create(),
        $this->otherTenant(),
    );

    $this->tenantJson('POST', "/api/dashboard/products/{$foreign->id}/pricing/recompute")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('skus', ['product_id' => $foreign->id, 'base_price' => '90.00']);
});
