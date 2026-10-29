<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\BundlePricing;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $pricing = BundlePricing::factory()->create()->fresh();

    expect(array_keys($pricing->toArray()))->toBe(['bundle_sku_id', 'tenant_id', 'mode', 'value', 'created_at', 'updated_at']);
});

test('BR-P15 the pricing is keyed by its bundle sku, the mode an enum and the value money', function (): void {
    $pricing = BundlePricing::factory()->percentOffSum('12.5')->create()->fresh();

    expect($pricing->mode)->toBe(BundlePriceMode::PercentOffSum)
        ->and($pricing->value)->toBe('12.50')
        ->and($pricing->bundleSku)->toBeInstanceOf(Sku::class)
        ->and(BundlePricing::query()->find($pricing->bundle_sku_id)?->is($pricing))->toBeTrue();
});

test('D93 a fixed price is above 0 and a % below 100', function (array $attributes): void {
    BundlePricing::factory()->create($attributes);
})->throws(QueryException::class)->with([
    'a fixed 0' => [['mode' => BundlePriceMode::Fixed, 'value' => '0.00']],
    'a % of 100' => [['mode' => BundlePriceMode::PercentOffSum, 'value' => '100.00']],
]);

test('BR-T03 a pricing created in a tenant belongs to it and is invisible elsewhere', function (): void {
    $pricing = BundlePricing::factory()->create();

    expect($pricing->tenant_id)->toBe($this->tenant->id)
        ->and($this->inTenant(fn () => BundlePricing::query()->count(), $this->otherTenant()))->toBe(0);
});
