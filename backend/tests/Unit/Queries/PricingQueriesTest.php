<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Pricing\Queries\BulkPricingTargetsQuery;
use App\Modules\Pricing\Queries\SkuPricingQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D91 the pricing query returns the active units of the product sku with tiers, unit and product loaded', function (): void {
    $product = Product::factory()->withSku()->create();
    $sku = $product->sku()->sole();
    SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => 10]);
    $carton = $this->addUnit($sku);
    SkuUnit::factory()->archived()->create(['sku_id' => $sku->id]);

    $units = app(SkuPricingQuery::class)->forProduct($product);

    expect($units->pluck('id')->all())->toBe([$this->defaultUnitId($sku), $carton->id])
        ->and($units->first()?->tiers)->toHaveCount(1)
        ->and($units->every(fn (SkuUnit $unit): bool => $unit->relationLoaded('unit') && $unit->sku->product === $product))->toBeTrue();
});

test('D91 the pricing query finds a unit of the product with its tiers and unit', function (): void {
    $product = Product::factory()->withSku()->create();
    $carton = $this->addUnit($product->sku()->sole(), tiers: [5 => '950.00']);

    $unit = app(SkuPricingQuery::class)->forUnit($product, SkuUnit::query()->findOrFail($carton->id));

    expect($unit->id)->toBe($carton->id)
        ->and($unit->tiers)->toHaveCount(1)
        ->and($unit->relationLoaded('unit'))->toBeTrue()
        ->and($unit->sku->product)->toBe($product);
});

test('D91 a unit of another product is not found for the product', function (): void {
    $product = Product::factory()->withSku()->create();
    $other = Product::factory()->withSku()->create()->sku()->sole();

    app(SkuPricingQuery::class)->forUnit($product, SkuUnit::query()->where('sku_id', $other->id)->sole());
})->throws(ModelNotFoundException::class);

test('D91 an archived unit of the product is not found', function (): void {
    $product = Product::factory()->withSku()->create();
    $archived = SkuUnit::factory()->archived()->create(['sku_id' => $product->sku()->sole()->id]);

    app(SkuPricingQuery::class)->forUnit($product, $archived);
})->throws(ModelNotFoundException::class);

test('BR-P02 a product without a sku has no pricing to show', function (): void {
    app(SkuPricingQuery::class)->forProduct(Product::factory()->create());
})->throws(ModelNotFoundException::class);

test('BR-PR11 D91 bulk targets are the active units of the matching products, in product order, with tiers, unit and product', function (): void {
    $first = Product::factory()->withSku()->create(['name' => 'علوم أولى']);
    $second = Product::factory()->withSku()->create(['name' => 'علوم ثانية']);
    $carton = $this->addUnit($first->sku()->sole());
    SkuUnit::factory()->archived()->create(['sku_id' => $second->sku()->sole()->id]);
    Product::factory()->withSku()->create(['name' => 'رياضيات']);
    Product::factory()->create(['name' => 'علوم بلا كود']);

    $units = app(BulkPricingTargetsQuery::class)->get(new ProductFilterData(q: 'علوم'));

    expect($units->map(fn (SkuUnit $unit): int => $unit->sku->product_id)->all())->toBe([$first->id, $first->id, $second->id])
        ->and($units->pluck('id')->all())->toContain($carton->id)
        ->and($units->every(fn (SkuUnit $unit): bool => $unit->relationLoaded('tiers') && $unit->relationLoaded('unit') && $unit->sku->relationLoaded('product')))->toBeTrue()
        ->and($units->first()?->sku->product->name)->toBe('علوم أولى');
});

test('D91 bulk targets can be only the units sold in one store unit', function (): void {
    $product = Product::factory()->withSku()->create(['name' => 'علوم أولى']);
    $carton = $this->addUnit($product->sku()->sole());

    $units = app(BulkPricingTargetsQuery::class)->get(new ProductFilterData(q: 'علوم'), $carton->unit_id);

    expect($units->pluck('id')->all())->toBe([$carton->id]);
});
