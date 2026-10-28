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
        'id', 'tenant_id', 'product_id', 'code', 'name', 'base_unit_id', 'created_at', 'updated_at',
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
