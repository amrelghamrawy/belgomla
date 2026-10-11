<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Actions\RecomputeFromPercentages;
use App\Modules\Pricing\Exceptions\MissingCoverPrice;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-PR10 after a cover price change the stored percentages are applied to the new cover price', function (): void {
    $sku = Sku::factory()->withCoverPrice('140.00', '25.00')->create(['base_price' => '90.00']);
    SkuPriceTier::factory()->for($sku)->percent('30.00')->create(['min_quantity' => 10, 'unit_price' => '84.00']);
    SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 50, 'unit_price' => '80.00']);

    $result = app(RecomputeFromPercentages::class)->handle($sku);

    expect($result->base_price)->toBe('105.00')
        ->and($result->base_price_percent)->toBe('25.00')
        ->and($result->tiers->map->only(['min_quantity', 'unit_price', 'discount_percent'])->all())->toBe([
            ['min_quantity' => 10, 'unit_price' => '98.00', 'discount_percent' => '30.00'],
            ['min_quantity' => 50, 'unit_price' => '80.00', 'discount_percent' => null],
        ]);
});

test('BR-L the recompute is audited as the price and tier changes it made', function (): void {
    $sku = Sku::factory()->withCoverPrice('140.00', '25.00')->create(['base_price' => '90.00']);
    SkuPriceTier::factory()->for($sku)->percent('30.00')->create(['min_quantity' => 10, 'unit_price' => '84.00']);

    app(RecomputeFromPercentages::class)->handle($sku);

    $logs = AuditLog::query()->get()->keyBy('action');

    expect($logs->keys()->sort()->values()->all())->toBe(['sku.price_changed', 'sku.tiers_changed'])
        ->and($logs['sku.price_changed']->old_values)->toEqual(['base_price' => '90.00'])
        ->and($logs['sku.price_changed']->new_values)->toEqual(['base_price' => '105.00']);
});

test('BR-PR10 a sku without stored percentages does not change and nothing is audited', function (): void {
    $sku = Sku::factory()->withCoverPrice('140.00')->create(['base_price' => '90.00']);
    SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 10, 'unit_price' => '84.00']);

    app(RecomputeFromPercentages::class)->handle($sku);

    expect($sku->refresh()->base_price)->toBe('90.00')
        ->and(SkuPriceTier::query()->sole()->unit_price)->toBe('84.00')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-PR10 stored percentages without a cover price cannot be recomputed', function (): void {
    $sku = Sku::factory()->create(['base_price' => '90.00', 'base_price_percent' => '25.00', 'cover_price' => null]);

    try {
        app(RecomputeFromPercentages::class)->handle($sku);
        $this->fail('Expected MissingCoverPrice');
    } catch (MissingCoverPrice $e) {
        expect($e->status)->toBe(422)
            ->and($e->errorCode)->toBe('cover_price_required')
            ->and($e->getMessage())->toBe('أدخل سعر القطاعي أولًا لحساب السعر بنسبة الخصم');
    }

    expect($sku->refresh()->base_price)->toBe('90.00');
});
