<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\DuplicateProduct;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P11 copies fields, units, prices and moq into an inactive product with a new code', function (): void {
    $source = Product::factory()->withSku([
        'code' => 'ADW-1', 'conversion_factor' => 20, 'cover_price' => '120.00', 'base_price' => '90.00', 'base_price_percent' => '25.00', 'moq' => 5,
    ])->create(['custom_fields' => ['brand' => 'x'], 'description' => 'وصف']);
    $source->load('skus.defaultUnit');
    $columns = ['unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq', 'is_default'];

    $copy = app(DuplicateProduct::class)->handle($source);

    expect($copy->status)->toBe(ProductStatus::Inactive)
        ->and($copy->only(['name', 'category_id', 'description', 'custom_fields']))->toBe($source->only(['name', 'category_id', 'description', 'custom_fields']))
        ->and($copy->skus->first()?->code)->toBe('ADW-1-COPY')
        ->and($copy->skus->first()?->base_unit_id)->toBe($source->skus->first()?->base_unit_id)
        ->and($copy->skus->first()?->defaultUnit?->only($columns))->toBe($source->skus->first()?->defaultUnit?->only($columns))
        ->and(AuditLog::query()->sole()->new_values)->toEqual(['source_product_id' => $source->id, 'sku.code' => 'ADW-1-COPY']);
});

test('BR-P11 copy codes count up and stay within 60 characters', function (): void {
    $source = Product::factory()->withSku(['code' => str_repeat('A', 60)])->create();
    Sku::factory()->create(['code' => str_repeat('A', 51).'-COPY']);

    $copy = app(DuplicateProduct::class)->handle($source);

    expect($copy->skus->first()->code)->toBe(str_repeat('A', 51).'-COPY-2')
        ->and(mb_strlen($copy->skus->first()->code))->toBeLessThanOrEqual(60);
});

test('relations are not copied and a product without a sku is copied without one', function (): void {
    $source = Product::factory()->create();
    ProductRelation::factory()->create(['product_id' => $source->id]);

    $copy = app(DuplicateProduct::class)->handle($source);

    expect($copy->relations()->count())->toBe(0)
        ->and($copy->skus->first())->toBeNull();
});

test('BR-P11 the tiers are copied exactly as stored, even when the cover price changed since', function (): void {
    $source = Product::factory()->withSku(['cover_price' => '140.00', 'base_price' => '90.00', 'base_price_percent' => '25.00'])->create();
    App\Modules\Pricing\Models\SkuPriceTier::factory()->percent('30.00')->create(['sku_id' => $source->skus()->sole()->id, 'min_quantity' => 10, 'unit_price' => '84.00']);

    $copy = app(DuplicateProduct::class)->handle($source->refresh());

    expect($copy->skus->first()?->defaultUnit?->base_price)->toBe('90.00')
        ->and($copy->skus->first()?->defaultUnit?->load('tiers')->tiers->map->only(['min_quantity', 'unit_price', 'discount_percent'])->all())
        ->toBe([['min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00']])
        ->and(AuditLog::query()->pluck('action')->all())->toBe(['product.duplicated']);
});

test('D91 every active unit is copied with its prices and tiers, an archived one is not', function (): void {
    $source = Product::factory()->withSku(['base_price' => '9.00'])->create();
    $sku = $source->skus()->with('defaultUnit')->sole();
    $carton = $this->addUnit($sku, ['base_price' => '100.00', 'moq' => 2], [5 => '95.00']);
    SkuUnit::factory()->archived()->create(['sku_id' => $sku->id]);

    $copy = app(DuplicateProduct::class)->handle($source->refresh());

    expect($copy->skus->first()?->activeUnits->map->only(['unit_id', 'conversion_factor', 'base_price', 'moq', 'is_default'])->all())->toBe([
        ['unit_id' => $sku->defaultUnit?->unit_id, 'conversion_factor' => 1, 'base_price' => '9.00', 'moq' => 1, 'is_default' => true],
        ['unit_id' => $carton->unit_id, 'conversion_factor' => 12, 'base_price' => '100.00', 'moq' => 2, 'is_default' => false],
    ])
        ->and($copy->skus->first()?->units()->count())->toBe(2)
        ->and($copy->skus->first()?->activeUnits->last()?->load('tiers')->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([5 => '95.00']);
});

test('BR-P11 BR-P14 every variant is copied with its options, values, label, status and order, each with a new code', function (): void {
    $source = $this->variantProduct(['أزرق', 'أسود'], ['S'], prices: ['أسود / S' => '12.00']);
    Sku::query()->where('code', 'PEN-2')->update(['status' => 'inactive']);

    $copy = app(DuplicateProduct::class)->handle($source->refresh())->load('skus.optionValues', 'skus.inventory', 'skus.defaultUnit', 'options.values');

    expect($copy->skus->map->only(['code', 'name', 'sort_order'])->all())->toBe([
        ['code' => 'PEN-1-COPY', 'name' => 'أزرق / S', 'sort_order' => 0],
        ['code' => 'PEN-2-COPY', 'name' => 'أسود / S', 'sort_order' => 1],
    ])
        ->and($copy->skus->pluck('status')->map->value->all())->toBe(['active', 'inactive'])
        ->and($copy->options->map(fn ($option): array => [$option->name, $option->values->pluck('value')->all()])->all())->toBe([['اللون', ['أزرق', 'أسود']], ['المقاس', ['S']]])
        ->and($copy->skus->last()?->optionValues->pluck('value')->sort()->values()->all())->toBe(['S', 'أسود'])
        ->and($copy->skus->last()?->optionValues->pluck('product_option_id')->intersect($copy->options->pluck('id'))->count())->toBe(2)
        ->and($copy->skus->last()?->defaultUnit?->base_price)->toBe('12.00')
        ->and($copy->skus->pluck('inventory.on_hand')->all())->toBe([0, 0])
        ->and(AuditLog::query()->where('action', 'product.duplicated')->sole()->new_values)->toEqual(['source_product_id' => $source->id, 'sku.code' => 'PEN-1-COPY', 'skus' => ['PEN-1-COPY', 'PEN-2-COPY']]);
});
