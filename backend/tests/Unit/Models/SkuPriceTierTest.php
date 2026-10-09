<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('to array', function (): void {
    $tier = $this->inTenant(fn () => SkuPriceTier::factory()->create()->fresh());

    expect(array_keys($tier->toArray()))->toBe([
        'id', 'tenant_id', 'sku_id', 'min_quantity', 'unit_price', 'discount_percent', 'created_at', 'updated_at',
    ]);
});

test('money and percent are 2-decimal strings, the threshold is an integer', function (): void {
    $tier = $this->inTenant(fn () => SkuPriceTier::factory()->percent('30')->create(['min_quantity' => '10', 'unit_price' => 84])->fresh());

    expect($tier->min_quantity)->toBe(10)
        ->and($tier->unit_price)->toBe('84.00')
        ->and($tier->discount_percent)->toBe('30.00');
});

test('a tier belongs to its sku and the sku lists its tiers lowest threshold first', function (): void {
    [$sku, $tier] = $this->inTenant(function (): array {
        $sku = Sku::factory()->create();
        SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 50, 'unit_price' => '80.00']);

        return [$sku, SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 10])];
    });

    $this->inTenant(fn () => $sku->load('tiers'));

    expect($this->inTenant(fn () => $tier->sku->is($sku)))->toBeTrue()
        ->and($sku->tiers->pluck('min_quantity')->all())->toBe([10, 50]);
});

test('BR-T03 a tier created in tenant context gets that tenant id', function (): void {
    $tier = $this->inTenant(fn () => SkuPriceTier::factory()->create());

    expect($tier->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a tier without a tenant in context throws', function (): void {
    SkuPriceTier::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 tier queries without a tenant in context return nothing', function (): void {
    $this->inTenant(fn () => SkuPriceTier::factory()->create());
    app(TenantContext::class)->clear();

    expect(SkuPriceTier::query()->count())->toBe(0);
});

test('BR-T03 tier queries in context never see another tenant', function (): void {
    $this->inTenant(fn () => SkuPriceTier::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn () => SkuPriceTier::query()->count()))->toBe(0);
});

test('BR-PR05 two tiers of one sku cannot share a threshold', function (): void {
    $this->inTenant(function (): void {
        $sku = Sku::factory()->create();
        SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 10]);
        SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 10]);
    });
})->throws(UniqueConstraintViolationException::class);

test('database checks guard the threshold, the price and the percent', function (array $attributes, string $constraint): void {
    expect(fn () => $this->inTenant(fn () => SkuPriceTier::factory()->create($attributes)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'BR-PR03 threshold at least 1' => [['min_quantity' => 0], 'sku_price_tiers_min_quantity_positive'],
    'BR-PR05 price above zero' => [['unit_price' => 0], 'sku_price_tiers_unit_price_positive'],
    'BR-PR10 percent below 100' => [['discount_percent' => 100], 'sku_price_tiers_discount_percent_range'],
    'BR-PR10 percent above 0' => [['discount_percent' => 0], 'sku_price_tiers_discount_percent_range'],
]);
