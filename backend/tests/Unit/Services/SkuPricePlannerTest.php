<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Exceptions\InvalidTiers;
use App\Modules\Pricing\Exceptions\MissingCoverPrice;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Pricing\Services\SkuPricePlanner;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->sku = Sku::factory()->withCoverPrice('120.00')->create(['base_price' => '100.00', 'moq' => 5]);
});

/**
 * @param  list<TierData>  $tiers
 * @return list<string>
 */
function plannerProblems(Sku $sku, string $basePrice, array $tiers): array
{
    try {
        app(SkuPricePlanner::class)->plan($sku, new SkuPricingData($basePrice, tiers: $tiers, coverPriceGiven: false));
    } catch (InvalidTiers $e) {
        return $e->details['tiers'] ?? [];
    }

    return [];
}

test('BR-PR10 a base price percent becomes the price off the cover price, the percent is kept for display', function (): void {
    $plan = app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData(null, '25.00', moq: 3, coverPriceGiven: false));

    expect($plan)->toBe([
        'cover_price' => '120.00',
        'base_price' => '90.00',
        'base_price_percent' => '25.00',
        'moq' => 3,
        'tiers' => [],
    ]);
});

test('BR-PR10 a new cover price is the one the percentages are taken from', function (): void {
    $plan = app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData(null, '25.00', coverPrice: '200.00'));

    expect($plan['cover_price'])->toBe('200.00')
        ->and($plan['base_price'])->toBe('150.00');
});

test('an absolute base price clears the stored percent', function (): void {
    $this->sku->update(['base_price_percent' => '25.00']);

    $plan = app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData('95.00', coverPriceGiven: false));

    expect($plan['base_price'])->toBe('95.00')
        ->and($plan['base_price_percent'])->toBeNull();
});

test('BR-PR03 tiers are planned lowest threshold first, from a price or a percent', function (): void {
    $plan = app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData('100.00', tiers: [
        new TierData(50, discountPercent: '35.00'),
        new TierData(10, '95.00'),
    ], coverPrice: '120.00'));

    expect($plan['tiers'])->toBe([
        ['min_quantity' => 10, 'unit_price' => '95.00', 'discount_percent' => null],
        ['min_quantity' => 50, 'unit_price' => '78.00', 'discount_percent' => '35.00'],
    ]);
});

test('tiers that are not given are the stored ones', function (): void {
    SkuPriceTier::factory()->for($this->sku)->create(['min_quantity' => 10, 'unit_price' => '90.00']);

    $plan = app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData('100.00'));

    expect($plan['tiers'])->toBe([['min_quantity' => 10, 'unit_price' => '90.00', 'discount_percent' => null]]);
});

test('current() mirrors what the sku stores; a sku not saved yet has no tiers', function (): void {
    SkuPriceTier::factory()->for($this->sku)->create(['min_quantity' => 10, 'unit_price' => '90.00']);

    expect(app(SkuPricePlanner::class)->current($this->sku->refresh()))->toBe([
        'cover_price' => '120.00',
        'base_price' => '100.00',
        'base_price_percent' => null,
        'moq' => 5,
        'tiers' => [['min_quantity' => 10, 'unit_price' => '90.00', 'discount_percent' => null]],
    ])
        ->and(app(SkuPricePlanner::class)->current(new Sku(['base_price' => '1.00', 'cover_price' => null, 'base_price_percent' => null, 'moq' => 1]))['tiers'])->toBe([]);
});

test('BR-PR05 two tiers with the same threshold are refused', function (): void {
    expect(plannerProblems($this->sku, '100.00', [new TierData(10, '90.00'), new TierData(10, '85.00')]))
        ->toBe(['الحد الأدنى 10 مكرر في أكثر من شريحة']);
});

test('D28 a tier above the base price is refused, a tier equal to it is allowed', function (): void {
    expect(plannerProblems($this->sku, '100.00', [new TierData(10, '100.01')]))->toBe(['سعر شريحة 10 أعلى من السعر الأساسي'])
        ->and(plannerProblems($this->sku, '100.00', [new TierData(1, '100.00')]))->toBe([]);
});

test('BR-PR05 a tier percent whose price rounds to zero is refused', function (): void {
    $this->sku->update(['cover_price' => '0.01']);

    expect(plannerProblems($this->sku, '0.01', [new TierData(10, discountPercent: '99.00')]))->toBe(['سعر شريحة 10 يجب أن يكون أكبر من صفر']);
});

test('every tier problem is reported at once', function (): void {
    expect(plannerProblems($this->sku, '100.00', [new TierData(10, '90.00'), new TierData(10, '150.00'), new TierData(20, '120.00')]))
        ->toBe(['الحد الأدنى 10 مكرر في أكثر من شريحة', 'سعر شريحة 10 أعلى من السعر الأساسي', 'سعر شريحة 20 أعلى من السعر الأساسي']);
});

test('BR-PR10 a percent without a cover price is refused', function (TierData|string $percent): void {
    $this->sku->update(['cover_price' => null]);
    $data = is_string($percent)
        ? new SkuPricingData(null, $percent, coverPriceGiven: false)
        : new SkuPricingData('100.00', tiers: [$percent], coverPriceGiven: false);

    app(SkuPricePlanner::class)->plan($this->sku, $data);
})->with([
    'base price percent' => ['25.00'],
    'tier percent' => [new TierData(10, discountPercent: '30.00')],
])->throws(MissingCoverPrice::class);

test('BR-PR01 a base price that rounds to zero is refused', function (): void {
    app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData(null, '99.00', coverPrice: '0.01'));
})->throws(InvalidPricingInput::class);

test('a price set without a price or a percent is a programming error', function (): void {
    app(SkuPricePlanner::class)->plan($this->sku, new SkuPricingData(null));
})->throws(InvalidArgumentException::class);
