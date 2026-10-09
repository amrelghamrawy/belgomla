<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\RemoveProductImage;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->product = Product::factory()->create();
});

test('removing the main image promotes the first remaining image by order', function (): void {
    $main = ProductImage::factory()->for($this->product)->main()->create(['sort_order' => 1]);
    $third = ProductImage::factory()->for($this->product)->create(['sort_order' => 3]);
    $second = ProductImage::factory()->for($this->product)->create(['sort_order' => 2]);

    app(RemoveProductImage::class)->handle($main);

    expect($second->refresh()->is_main)->toBeTrue()
        ->and($third->refresh()->is_main)->toBeFalse();
});

test('removing a gallery image keeps the main image and deletes the file, audited', function (): void {
    Storage::disk('public')->put('p/g.jpg', 'x');
    $main = ProductImage::factory()->for($this->product)->main()->create();
    $gallery = ProductImage::factory()->for($this->product)->create(['path' => 'p/g.jpg']);

    app(RemoveProductImage::class)->handle($gallery);

    Storage::disk('public')->assertMissing('p/g.jpg');
    expect($main->refresh()->is_main)->toBeTrue()
        ->and(AuditLog::query()->sole()->old_values)->toEqual(['product_id' => $this->product->id, 'path' => 'p/g.jpg', 'is_main' => false]);
});

test('removing the last image leaves an empty gallery', function (): void {
    $only = ProductImage::factory()->for($this->product)->main()->create();

    app(RemoveProductImage::class)->handle($only);

    expect(ProductImage::query()->count())->toBe(0);
});
