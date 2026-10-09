<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Pricing\Queries\BulkPricingTargetsQuery;
use App\Modules\Pricing\Queries\SkuPricingQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the pricing query returns the product sku with its tiers and product loaded', function (): void {
    $product = Product::factory()->withSku()->create();
    SkuPriceTier::factory()->for($product->sku)->create(['min_quantity' => 10]);

    $sku = app(SkuPricingQuery::class)->forProduct($product);

    expect($sku->product_id)->toBe($product->id)
        ->and($sku->relationLoaded('tiers'))->toBeTrue()
        ->and($sku->tiers)->toHaveCount(1)
        ->and($sku->product)->toBe($product);
});

test('BR-P02 a product without a sku has no pricing to show', function (): void {
    app(SkuPricingQuery::class)->forProduct(Product::factory()->create());
})->throws(ModelNotFoundException::class);

test('BR-PR11 bulk targets are the skus of the matching products, in product order, with tiers and product', function (): void {
    $first = Product::factory()->withSku()->create(['name' => 'علوم أولى']);
    $second = Product::factory()->withSku()->create(['name' => 'علوم ثانية']);
    Product::factory()->withSku()->create(['name' => 'رياضيات']);
    Product::factory()->create(['name' => 'علوم بلا كود']);

    $skus = app(BulkPricingTargetsQuery::class)->get(new ProductFilterData(q: 'علوم'));

    expect($skus->pluck('product_id')->all())->toBe([$first->id, $second->id])
        ->and($skus->every(fn ($sku): bool => $sku->relationLoaded('tiers') && $sku->relationLoaded('product')))->toBeTrue()
        ->and($skus->first()->product->name)->toBe('علوم أولى');
});
