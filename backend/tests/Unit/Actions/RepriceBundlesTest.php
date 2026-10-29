<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Actions\RepriceBundles;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->arabic = $this->orderableSku(['code' => 'AR-1', 'base_price' => '100.00'], name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1', 'base_price' => '50.00'], name: 'رياضيات');
    app(TenantContext::class)->set($this->tenant);

    $this->priceOf = fn ($bundle): string => SkuUnit::query()->where('sku_id', $this->bundleSku($bundle)->id)->value('base_price');
    $this->reprice = fn (string $price) => app(UpdateSkuPricing::class)->handle(SkuUnit::query()->findOrFail($this->defaultUnitId($this->arabic)), new SkuPricingData($price, moq: 5), '7f0c4d7e-5b1a-4c4e-9a6b-1c2d3e4f5a6b');
});

test('BR-P15 D93 a percent-off-sum bundle follows a component\'s new base price', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 2], BundlePriceMode::PercentOffSum, '10.00'); // 200 − 10 %

    ($this->reprice)('120.00');

    expect(($this->priceOf)($bundle))->toBe('198.00');
});

test('BR-L the repricing is audited on the bundle unit with the caller\'s batch', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1], BundlePriceMode::PercentOffSum, '10.00');

    ($this->reprice)('120.00');

    $record = AuditLog::query()->where('action', 'sku.price_changed')->where('entity_id', SkuUnit::query()->where('sku_id', $this->bundleSku($bundle)->id)->value('id'))->sole();

    expect($record->old_values['base_price'] ?? null)->toBe('90.00')
        ->and($record->new_values['base_price'] ?? null)->toBe('108.00')
        ->and($record->batch_id)->toBe('7f0c4d7e-5b1a-4c4e-9a6b-1c2d3e4f5a6b');
});

test('BR-P15 a fixed-price bundle does not move', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1], BundlePriceMode::Fixed, '95.00');

    ($this->reprice)('120.00');

    expect(($this->priceOf)($bundle))->toBe('95.00');
});

test('D93 a bundle whose price does not change is not audited', function (): void {
    $this->gradeSet([$this->maths->id => 1], BundlePriceMode::PercentOffSum, '10.00');

    app(RepriceBundles::class)->handle([$this->defaultUnitId($this->maths)]);

    expect(AuditLog::query()->where('action', 'sku.price_changed')->count())->toBe(0);
});

test('D93 a unit in no bundle reprices nothing', function (): void {
    $bundle = $this->gradeSet([$this->maths->id => 1], BundlePriceMode::PercentOffSum, '10.00');

    ($this->reprice)('120.00');

    expect(($this->priceOf)($bundle))->toBe('45.00');
});
