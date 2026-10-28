<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Models\SkuPriceTier;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-PR10 recompute applies the stored percentages to the new cover price, audited', function (): void {
    $product = $this->inTenant(function (): Product {
        $product = Product::factory()->withSku(['cover_price' => '140.00', 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create();
        SkuPriceTier::factory()->percent('30.00')->create(['sku_id' => $product->sku()->sole()->id, 'min_quantity' => 10, 'unit_price' => '84.00']);

        return $product;
    });

    $this->tenantJson('POST', "/api/dashboard/products/{$product->id}/pricing/recompute")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.base_price', '105.00')
        ->assertJsonPath('data.0.tiers', [['min_quantity' => 10, 'unit_price' => '98.00', 'discount_percent' => '30.00']]);

    $logs = $this->inTenant(fn () => AuditLog::query()->get());

    expect($logs->pluck('action')->sort()->values()->all())->toBe(['sku.price_changed', 'sku.tiers_changed'])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id]);
});

test('D91 recompute answers every unit of the product, each off its own cover price', function (): void {
    $product = $this->inTenant(fn (): Product => Product::factory()->withSku(['cover_price' => '140.00', 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create());
    $carton = $this->addUnit($this->inTenant(fn () => $product->sku()->sole()), ['cover_price' => '1680.00', 'base_price' => '1000.00', 'base_price_percent' => '20.00']);

    $this->tenantJson('POST', "/api/dashboard/products/{$product->id}/pricing/recompute")
        ->assertOk()
        ->assertJsonPath('data.0.base_price', '105.00')
        ->assertJsonPath('data.1.sku_unit_id', $carton->id)
        ->assertJsonPath('data.1.base_price', '1344.00');
});

test('BR-PR10 recompute without a reference price is refused with cover_price_required, named after the section', function (CategoryKind $kind, string $message): void {
    $product = $this->inTenant(fn () => Product::factory()
        ->for(Category::factory()->kind($kind))
        ->withSku(['cover_price' => null, 'base_price' => '90.00', 'base_price_percent' => '25.00'])
        ->create());

    $this->tenantJson('POST', "/api/dashboard/products/{$product->id}/pricing/recompute")
        ->assertUnprocessable()
        ->assertExactJson(['error' => ['code' => 'cover_price_required', 'message' => $message]]);
})->with([
    'books say cover price' => [CategoryKind::Books, 'أدخل سعر الغلاف أولًا لحساب السعر بنسبة الخصم'],
    'toys say retail price' => [CategoryKind::Toys, 'أدخل سعر القطاعي أولًا لحساب السعر بنسبة الخصم'],
]);

test('BR-T04 recomputing another tenant product is 404 and changes nothing', function (): void {
    $foreign = $this->inTenant(
        fn () => Product::factory()->withSku(['cover_price' => '140.00', 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create(),
        $this->otherTenant(),
    );

    $this->tenantJson('POST', "/api/dashboard/products/{$foreign->id}/pricing/recompute")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('sku_units', ['sku_id' => DB::table('skus')->where('product_id', $foreign->id)->value('id'), 'base_price' => '90.00']);
});
