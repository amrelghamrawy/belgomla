<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('to array', function (): void {
    $unit = $this->inTenant(fn () => SkuUnit::factory()->create()->fresh());

    expect(array_keys($unit->toArray()))->toBe([
        'id', 'tenant_id', 'sku_id', 'unit_id', 'conversion_factor', 'base_price', 'base_price_percent', 'cover_price',
        'moq', 'is_default', 'sort_order', 'status', 'created_at', 'updated_at',
    ]);
});

test('money and percent are 2-decimal strings, quantities integers, the flag a bool and the status an enum', function (): void {
    $unit = $this->inTenant(fn () => SkuUnit::factory()->create([
        'base_price' => 90, 'base_price_percent' => '25', 'cover_price' => 120, 'conversion_factor' => '20', 'moq' => '5', 'sort_order' => '3',
    ])->fresh());

    expect($unit->base_price)->toBe('90.00')
        ->and($unit->base_price_percent)->toBe('25.00')
        ->and($unit->cover_price)->toBe('120.00')
        ->and($unit->conversion_factor)->toBe(20)
        ->and($unit->moq)->toBe(5)
        ->and($unit->sort_order)->toBe(3)
        ->and($unit->is_default)->toBeFalse()
        ->and($unit->status)->toBe(ArchiveStatus::Active)
        ->and($unit->isActive())->toBeTrue();
});

test('an archived unit is not active', function (): void {
    expect($this->inTenant(fn () => SkuUnit::factory()->archived()->create()->isActive()))->toBeFalse();
});

test('relations: its sku, its store unit and its tiers lowest threshold first', function (): void {
    $unit = $this->inTenant(function (): SkuUnit {
        $unit = SkuUnit::factory()->create();
        SkuPriceTier::factory()->for($unit)->create(['min_quantity' => 50, 'unit_price' => '900.00']);
        SkuPriceTier::factory()->for($unit)->create(['min_quantity' => 10, 'unit_price' => '1000.00']);

        return $unit->load('sku', 'unit', 'tiers');
    });

    expect($unit->sku)->toBeInstanceOf(Sku::class)
        ->and($unit->unit)->toBeInstanceOf(Unit::class)
        ->and($unit->tiers->pluck('min_quantity')->all())->toBe([10, 50]);
});

test('BR-T03 a unit created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn () => SkuUnit::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a unit without a tenant in context throws', function (): void {
    SkuUnit::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 unit queries without a tenant in context return nothing', function (): void {
    $this->inTenant(fn () => SkuUnit::factory()->create());
    app(TenantContext::class)->clear();

    expect(SkuUnit::query()->count())->toBe(0);
});

test('BR-T03 unit queries in context never see another tenant', function (): void {
    $this->inTenant(fn () => SkuUnit::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn () => SkuUnit::query()->count()))->toBe(0);
});

test('D91 a sku is sold at most once in a store unit', function (): void {
    $this->inTenant(function (): void {
        $sku = Sku::factory()->create();
        SkuUnit::factory()->create(['sku_id' => $sku->id, 'unit_id' => $sku->defaultUnit?->unit_id]);
    });
})->throws(UniqueConstraintViolationException::class);

test('D91 a sku has at most one default unit', function (): void {
    $this->inTenant(fn () => SkuUnit::factory()->create(['sku_id' => Sku::factory()->create()->id, 'is_default' => true]));
})->throws(UniqueConstraintViolationException::class, 'sku_units_one_default');

test('database checks guard the factor, the price, the moq and an archived default', function (array $attributes, string $constraint): void {
    expect(fn () => $this->inTenant(fn () => SkuUnit::factory()->create($attributes)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'BR-U03 factor at least 1' => [['conversion_factor' => 0], 'sku_units_conversion_factor_positive'],
    'BR-PR01 base price must be positive' => [['base_price' => 0], 'sku_units_base_price_positive'],
    'BR-MOQ01 moq at least 1' => [['moq' => 0], 'sku_units_moq_positive'],
    'D91 the default unit is active' => [['status' => 'archived', 'is_default' => true], 'sku_units_default_active'],
]);
