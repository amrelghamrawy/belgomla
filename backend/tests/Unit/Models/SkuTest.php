<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $sku = $this->inTenant(fn () => Sku::factory()->create()->fresh());

    expect(array_keys($sku->toArray()))->toBe([
        'id', 'tenant_id', 'product_id', 'code', 'name', 'base_unit_id', 'created_at', 'updated_at', 'status', 'sort_order',
    ]);
});

test('relations: product and base unit', function (): void {
    $sku = $this->inTenant(fn () => Sku::factory()->create()->load('product', 'baseUnit'));

    expect($sku->product)->toBeInstanceOf(Product::class)
        ->and($sku->baseUnit)->toBeInstanceOf(Unit::class);
});

test('D91 units lists every unit the default first then by sort order, active units leave the archived out', function (): void {
    $sku = Sku::factory()->create();
    $box = $this->addUnit($sku, ['sort_order' => 2]);
    $carton = $this->addUnit($sku, ['sort_order' => 1]);
    $old = SkuUnit::factory()->archived()->create(['sku_id' => $sku->id, 'sort_order' => 0]);

    $sku->load('units', 'activeUnits');

    expect($sku->units->pluck('id')->all())->toBe([$sku->defaultUnit?->id, $old->id, $carton->id, $box->id])
        ->and($sku->activeUnits->pluck('id')->all())->toBe([$sku->defaultUnit?->id, $carton->id, $box->id]);
});

test('D91 the default unit is the one flagged', function (): void {
    $sku = Sku::factory()->create();
    $this->addUnit($sku);

    expect($sku->fresh()?->defaultUnit?->conversion_factor)->toBe(1)
        ->and($sku->fresh()?->defaultUnit?->is_default)->toBeTrue();
});

test('D36 D91 the smallest unit is the active one with the fewest base units', function (): void {
    $sku = Sku::factory()->create(['conversion_factor' => 6]);
    $this->addUnit($sku, ['conversion_factor' => 24]);
    SkuUnit::factory()->archived()->create(['sku_id' => $sku->id, 'conversion_factor' => 2]);

    expect($sku->fresh()?->smallestUnit?->conversion_factor)->toBe(6)
        ->and(Sku::query()->with('smallestUnit')->findOrFail($sku->id)->smallestUnit?->conversion_factor)->toBe(6);
});

test('BR-P03 a sku code is unique per tenant', function (): void {
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'PEN-001']));

    $this->inTenant(fn () => Sku::factory()->create(['code' => 'PEN-001']));
})->throws(UniqueConstraintViolationException::class);

test('BR-P03 the same sku code is allowed in another tenant', function (): void {
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'PEN-001']));
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'PEN-001']), $this->otherTenant());

    expect(Sku::withoutTenantScope()->where('code', 'PEN-001')->count())->toBe(2);
});

test('BR-P14 a sku is active by default and its status is cast', function (): void {
    $sku = Sku::factory()->create()->fresh();
    $inactive = Sku::factory()->create(['status' => App\Modules\Catalog\Enums\SkuStatus::Inactive])->fresh();

    expect($sku?->status)->toBe(App\Modules\Catalog\Enums\SkuStatus::Active)
        ->and($sku?->isActive())->toBeTrue()
        ->and($sku?->sort_order)->toBe(0)
        ->and($inactive?->isActive())->toBeFalse();
});

test('BR-P14 the database refuses an unknown sku status', function (): void {
    Illuminate\Support\Facades\DB::table('skus')->where('id', Sku::factory()->create()->id)->update(['status' => 'archived']);
})->throws(Illuminate\Database\QueryException::class);

test('BR-P14 a variant names one value of each option of its product', function (): void {
    $product = $this->variantProduct(['أزرق', 'أسود'], ['S', 'M']);

    $sku = Sku::query()->with('optionValues')->where('code', 'PEN-2')->sole();

    expect($sku->optionValues->pluck('value')->sort()->values()->all())->toBe(['M', 'أزرق'])
        ->and($sku->name)->toBe('أزرق / M')
        ->and($sku->product_id)->toBe($product->id);
});
