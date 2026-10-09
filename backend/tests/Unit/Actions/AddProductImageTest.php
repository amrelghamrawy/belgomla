<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\AddProductImage;
use App\Modules\Catalog\Exceptions\GalleryFull;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->product = Product::factory()->create();
});

test('BR-P07 the first image is main and later ones append to the gallery', function (): void {
    $first = app(AddProductImage::class)->handle($this->product, UploadedFile::fake()->image('a.jpg'));
    $second = app(AddProductImage::class)->handle($this->product, UploadedFile::fake()->image('b.jpg'));

    expect($first->is_main)->toBeTrue()
        ->and($second->is_main)->toBeFalse()
        ->and($second->sort_order)->toBe(2);
    Storage::disk('public')->assertExists([$first->path, $second->path]);
});

test('BR-P07 a new main image demotes the previous one', function (): void {
    $old = ProductImage::factory()->for($this->product)->main()->create();

    $new = app(AddProductImage::class)->handle($this->product, UploadedFile::fake()->image('a.jpg'), isMain: true);

    expect($new->is_main)->toBeTrue()
        ->and($old->refresh()->is_main)->toBeFalse();
});

test('a gallery without a main image gets one from the next upload', function (): void {
    ProductImage::factory()->for($this->product)->create();

    expect(app(AddProductImage::class)->handle($this->product, UploadedFile::fake()->image('a.jpg'))->is_main)->toBeTrue();
});

test('D26 the gallery holds at most 8 images and nothing is stored beyond', function (): void {
    ProductImage::factory()->count(8)->for($this->product)->create();

    expect(fn () => app(AddProductImage::class)->handle($this->product, UploadedFile::fake()->image('a.jpg')))->toThrow(GalleryFull::class);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('images of other products do not count towards the limit', function (): void {
    ProductImage::factory()->count(8)->create();

    expect(app(AddProductImage::class)->handle($this->product, UploadedFile::fake()->image('a.jpg'))->sort_order)->toBe(1);
});
