<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Services\FacetCacheVersion;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $option = ProductOption::factory()->create()->fresh();

    expect(array_keys($option->toArray()))->toBe(['id', 'tenant_id', 'product_id', 'name', 'sort_order', 'created_at', 'updated_at']);
});

test('belongs to its product and lists its values in order', function (): void {
    $option = ProductOption::factory()->create(['name' => 'اللون']);
    ProductOptionValue::factory()->create(['product_option_id' => $option->id, 'value' => 'أسود', 'sort_order' => 1]);
    ProductOptionValue::factory()->create(['product_option_id' => $option->id, 'value' => 'أزرق', 'sort_order' => 0]);

    expect($option->product)->toBeInstanceOf(Product::class)
        ->and($option->values->pluck('value')->all())->toBe(['أزرق', 'أسود'])
        ->and($option->sort_order)->toBe(0);
});

test('BR-P14 an option name is unique within its product', function (): void {
    $product = Product::factory()->create();
    ProductOption::factory()->create(['product_id' => $product->id, 'name' => 'اللون']);
    ProductOption::factory()->create(['product_id' => $product->id, 'name' => 'اللون']);
})->throws(UniqueConstraintViolationException::class);

test('BR-T03 an option created in a tenant belongs to it and is invisible elsewhere', function (): void {
    $option = ProductOption::factory()->create();

    expect($option->tenant_id)->toBe($this->tenant->id)
        ->and($this->inTenant(fn () => ProductOption::query()->count(), $this->otherTenant()))->toBe(0);
});

test('Tech §9b saving an option bumps the facet cache version', function (): void {
    $before = app(FacetCacheVersion::class)->current($this->tenant->id);

    ProductOption::factory()->create();

    expect(app(FacetCacheVersion::class)->current($this->tenant->id))->toBeGreaterThan($before);
});
