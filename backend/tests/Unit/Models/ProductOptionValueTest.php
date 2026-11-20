<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $value = ProductOptionValue::factory()->create()->fresh();

    expect(array_keys($value->toArray()))->toBe(['id', 'tenant_id', 'product_option_id', 'value', 'sort_order', 'image_id', 'created_at', 'updated_at']);
});

test('belongs to its option and may show one product image as its swatch', function (): void {
    $image = ProductImage::factory()->create();
    $value = ProductOptionValue::factory()->create(['image_id' => $image->id])->fresh();

    expect($value->option)->toBeInstanceOf(ProductOption::class)
        ->and($value->image?->is($image))->toBeTrue()
        ->and($value->image_id)->toBe($image->id);
});

test('BR-P14 a value is unique within its option', function (): void {
    $option = ProductOption::factory()->create();
    ProductOptionValue::factory()->create(['product_option_id' => $option->id, 'value' => 'أزرق']);
    ProductOptionValue::factory()->create(['product_option_id' => $option->id, 'value' => 'أزرق']);
})->throws(UniqueConstraintViolationException::class);

test('deleting the swatch image leaves the value without one', function (): void {
    $image = ProductImage::factory()->create();
    $value = ProductOptionValue::factory()->create(['image_id' => $image->id]);

    $image->delete();

    expect($value->fresh()?->image_id)->toBeNull();
});
