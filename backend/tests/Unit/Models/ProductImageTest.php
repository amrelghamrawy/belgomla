<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $image = $this->inTenant(fn () => ProductImage::factory()->create()->fresh());

    expect(array_keys($image->toArray()))->toBe([
        'id', 'tenant_id', 'product_id', 'path', 'is_main', 'sort_order', 'created_at', 'updated_at',
    ]);
});

test('casts and the public url', function (): void {
    $image = $this->inTenant(fn () => ProductImage::factory()->main()->create(['path' => 'tenants/1/products/a.jpg', 'sort_order' => '2'])->fresh());

    expect($image->is_main)->toBeTrue()
        ->and($image->sort_order)->toBe(2)
        ->and($image->url())->toEndWith('/storage/tenants/1/products/a.jpg');
});

test('belongs to its product', function (): void {
    $image = $this->inTenant(fn () => ProductImage::factory()->create());

    expect($image->product)->toBeInstanceOf(Product::class);
});

test('BR-P07 the database allows only one main image per product', function (): void {
    $this->inTenant(function (): void {
        $product = Product::factory()->create();
        ProductImage::factory()->for($product)->main()->create();
        ProductImage::factory()->for($product)->main()->create();
    });
})->throws(UniqueConstraintViolationException::class);
