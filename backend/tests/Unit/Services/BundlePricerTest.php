<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\PromotionPrice;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Services\BundlePricer;
use App\Modules\Pricing\Services\PriceResolver;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->arabic = $this->orderableSku(['code' => 'AR-1', 'base_price' => '92.00'], name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1', 'base_price' => '113.50'], name: 'رياضيات');
    app(TenantContext::class)->set($this->tenant);

    $this->items = fn (int $arabic = 1, int $maths = 2): array => [
        new BundleItem(['quantity' => $arabic])->setRelation('componentUnit', SkuUnit::query()->with('tiers')->findOrFail($this->defaultUnitId($this->arabic))),
        new BundleItem(['quantity' => $maths])->setRelation('componentUnit', SkuUnit::query()->with('tiers')->findOrFail($this->defaultUnitId($this->maths))),
    ];
});

test('BR-P15 the sum is every component base price times its quantity', function (): void {
    expect(app(BundlePricer::class)->sum(($this->items)()))->toBe('319.00');
});

test('BR-P15 a fixed bundle costs its fixed price whatever its components cost', function (): void {
    expect(app(BundlePricer::class)->price(new BundlePricingData(BundlePriceMode::Fixed, '250'), ($this->items)()))->toBe('250.00');
});

test('BR-P15 D27 a percent-off-sum bundle costs the sum less the %, half-up to the piaster', function (string $percent, string $price): void {
    expect(app(BundlePricer::class)->price(new BundlePricingData(BundlePriceMode::PercentOffSum, $percent), ($this->items)()))->toBe($price);
})->with([
    'no discount' => ['0.00', '319.00'],
    '10 %' => ['10.00', '287.10'],
    '12.5 % rounds half-up' => ['12.50', '279.13'], // 279.125
    '99.99 % never below 0.01' => ['99.99', '0.03'],
]);

test('BR-P15 a bundle price never goes below 0.01', function (): void {
    $items = ($this->items)(1, 0);
    $items[0]->componentUnit->base_price = '0.01';

    expect(app(BundlePricer::class)->price(new BundlePricingData(BundlePriceMode::PercentOffSum, '99.00'), [$items[0]]))->toBe('0.01');
});

test('BR-PR14 BR-PR15 the shopper\'s sum prices each component at their own listed price', function (): void {
    $context = new PricingContext('10.00', [], [$this->maths->id => new PromotionPrice(1, 'عرض', '20.00', CarbonImmutable::parse('2026-12-01'))]);

    expect(app(BundlePricer::class)->listedSum(($this->items)(), $context))->toBe('264.40'); // 92 − 10 % = 82.80, 113.50 − 20 % = 90.80 × 2
});

test('BR-PR14 BR-PR15 group and promotion apply to the bundle price like to any SKU unit', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 2], BundlePriceMode::Fixed, '300.00');
    $unit = SkuUnit::query()->with('tiers')->where('sku_id', $this->bundleSku($bundle)->id)->sole();

    $group = app(PriceResolver::class)->resolve($unit, 1, new PricingContext('10.00'));
    $promoted = app(PriceResolver::class)->resolve($unit, 1, new PricingContext(null, [], [$unit->sku_id => new PromotionPrice(3, 'عرض الباقات', '25.00', CarbonImmutable::parse('2026-12-01'))]));

    expect([$group->unitPrice, $group->source])->toBe(['270.00', PriceSource::Group])
        ->and([$promoted->unitPrice, $promoted->source])->toBe(['225.00', PriceSource::Promotion]);
});

test('BR-P15 the stored pricing of a bundle is read back, a simple SKU has none', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1], BundlePriceMode::PercentOffSum, '15.50');

    expect(app(BundlePricer::class)->pricingOf($this->bundleSku($bundle)))->toEqual(new BundlePricingData(BundlePriceMode::PercentOffSum, '15.50'))
        ->and(app(BundlePricer::class)->pricingOf($this->arabic))->toBeNull();
});
