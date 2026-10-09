<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $sku = $this->inTenant(fn () => Sku::factory()->create()->fresh());

    expect(array_keys($sku->toArray()))->toBe([
        'id', 'tenant_id', 'product_id', 'code', 'name', 'selling_unit_id', 'base_unit_id', 'conversion_factor',
        'cover_price', 'base_price', 'base_price_percent', 'moq', 'created_at', 'updated_at',
    ]);
});

test('money and percent are 2-decimal strings, quantities are integers', function (): void {
    $sku = $this->inTenant(fn () => Sku::factory()->withCoverPrice('120', '25')->create([
        'base_price' => 90, 'conversion_factor' => '20', 'moq' => '5',
    ])->fresh());

    expect($sku->cover_price)->toBe('120.00')
        ->and($sku->base_price)->toBe('90.00')
        ->and($sku->base_price_percent)->toBe('25.00')
        ->and($sku->conversion_factor)->toBe(20)
        ->and($sku->moq)->toBe(5);
});

test('relations: product, selling unit and base unit', function (): void {
    $sku = $this->inTenant(fn () => Sku::factory()->create()->load('product', 'sellingUnit', 'baseUnit'));

    expect($sku->product)->toBeInstanceOf(Product::class)
        ->and($sku->sellingUnit)->toBeInstanceOf(Unit::class)
        ->and($sku->baseUnit)->toBeInstanceOf(Unit::class)
        ->and($sku->sellingUnit->isNot($sku->baseUnit))->toBeTrue();
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

test('database checks guard prices, moq and conversion factor', function (array $attributes, string $constraint): void {
    expect(fn () => $this->inTenant(fn () => Sku::factory()->create($attributes)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'BR-PR01 base price must be positive' => [['base_price' => 0], 'skus_base_price_positive'],
    'BR-MOQ01 moq at least 1' => [['moq' => 0], 'skus_moq_positive'],
    'BR-U02 conversion factor at least 1' => [['conversion_factor' => 0], 'skus_conversion_factor_positive'],
]);
