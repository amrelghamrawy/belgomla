<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\DuplicateProduct;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P11 copies fields, units, prices and moq into an inactive product with a new code', function (): void {
    $source = Product::factory()->withSku([
        'code' => 'ADW-1', 'conversion_factor' => 20, 'cover_price' => '120.00', 'base_price' => '90.00', 'base_price_percent' => '25.00', 'moq' => 5,
    ])->create(['custom_fields' => ['brand' => 'x'], 'description' => 'وصف']);
    $source->load('sku');

    $copy = app(DuplicateProduct::class)->handle($source);

    expect($copy->status)->toBe(ProductStatus::Inactive)
        ->and($copy->only(['name', 'category_id', 'description', 'custom_fields']))->toBe($source->only(['name', 'category_id', 'description', 'custom_fields']))
        ->and($copy->sku->code)->toBe('ADW-1-COPY')
        ->and($copy->sku->only(['selling_unit_id', 'base_unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq']))
        ->toBe($source->sku->only(['selling_unit_id', 'base_unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq']))
        ->and(AuditLog::query()->sole()->new_values)->toEqual(['source_product_id' => $source->id, 'sku.code' => 'ADW-1-COPY']);
});

test('BR-P11 copy codes count up and stay within 60 characters', function (): void {
    $source = Product::factory()->withSku(['code' => str_repeat('A', 60)])->create();
    Sku::factory()->create(['code' => str_repeat('A', 51).'-COPY']);

    $copy = app(DuplicateProduct::class)->handle($source);

    expect($copy->sku->code)->toBe(str_repeat('A', 51).'-COPY-2')
        ->and(mb_strlen($copy->sku->code))->toBeLessThanOrEqual(60);
});

test('relations are not copied and a product without a sku is copied without one', function (): void {
    $source = Product::factory()->create();
    ProductRelation::factory()->create(['product_id' => $source->id]);

    $copy = app(DuplicateProduct::class)->handle($source);

    expect($copy->relations()->count())->toBe(0)
        ->and($copy->sku)->toBeNull();
});
