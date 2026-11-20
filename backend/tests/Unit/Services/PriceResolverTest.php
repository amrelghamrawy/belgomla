<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Database\Eloquent\Collection;

/*
| The resolver is pure: every SKU unit here (V2 Task 13, D91) is built in memory with its tiers set as a loaded relation,
| no database row exists.
*/

/**
 * @param  list<array{0: int, 1: string, 2?: string}>  $tiers  [min_quantity, unit_price, discount_percent?]
 */
function resolverSku(string $basePrice = '100.00', array $tiers = [], ?string $coverPrice = null, ?string $basePricePercent = null, int $moq = 1): SkuUnit
{
    $sku = new SkuUnit(['base_price' => $basePrice, 'cover_price' => $coverPrice, 'base_price_percent' => $basePricePercent, 'moq' => $moq]);

    return $sku->setRelation('tiers', new Collection(array_map(
        fn (array $tier): SkuPriceTier => new SkuPriceTier(['min_quantity' => $tier[0], 'unit_price' => $tier[1], 'discount_percent' => $tier[2] ?? null]),
        $tiers,
    )));
}

test('BR-PR04 the highest tier whose minimum is at most the quantity wins, else the base price', function (int $quantity, string $unitPrice, ?int $tier): void {
    $sku = resolverSku('100.00', [[10, '90.00'], [100, '80.00']]);

    $price = app(PriceResolver::class)->resolve($sku, $quantity);

    expect($price->unitPrice)->toBe($unitPrice)
        ->and($price->tierMinQuantity)->toBe($tier);
})->with([
    'quantity 1' => [1, '100.00', null],
    'one below the first tier (min − 1)' => [9, '100.00', null],
    'exactly the first tier' => [10, '90.00', 10],
    'between tiers' => [35, '90.00', 10],
    'one below the second tier' => [99, '90.00', 10],
    'exactly the second tier' => [100, '80.00', 100],
    'far above the last tier' => [5000, '80.00', 100],
]);

test('BR-PR04 without tiers the base price applies', function (): void {
    expect(app(PriceResolver::class)->resolve(resolverSku('100.00'), 7)->unitPrice)->toBe('100.00');
});

test('BR-PR04 the order the tiers were loaded in does not matter', function (): void {
    $sku = resolverSku('100.00', [[100, '80.00'], [10, '90.00'], [50, '85.00']]);

    expect(app(PriceResolver::class)->resolve($sku, 60)->unitPrice)->toBe('85.00');
});

test('BR-MOQ01 the moq plays no part in the price', function (): void {
    $sku = resolverSku('100.00', [[10, '90.00']], moq: 50);

    expect(app(PriceResolver::class)->resolve($sku, 10)->unitPrice)->toBe('90.00');
});

test('BR-PR07 the line total is unit price times quantity as an exact money string', function (): void {
    $price = app(PriceResolver::class)->resolve(resolverSku('100.00', [[10, '89.99']]), 35);

    expect($price->lineTotal)->toBe('3149.65');
});

test('BR-PR10 the resolved price carries the cover price and the discount it was entered as', function (): void {
    $sku = resolverSku('90.00', [[10, '84.00', '30.00']], coverPrice: '120.00', basePricePercent: '25.00');

    $base = app(PriceResolver::class)->resolve($sku, 1);
    $tier = app(PriceResolver::class)->resolve($sku, 10);

    expect($base->coverPrice)->toBe('120.00')
        ->and($base->discountPercent)->toBe('25.00')
        ->and($tier->discountPercent)->toBe('30.00');
});

test('BR-PR10 an absolute price shows the discount it implies off the cover price', function (): void {
    $sku = resolverSku('90.00', [[10, '80.00']], coverPrice: '120.00');

    expect(app(PriceResolver::class)->resolve($sku, 1)->discountPercent)->toBe('25.00')
        ->and(app(PriceResolver::class)->resolve($sku, 10)->discountPercent)->toBe('33.33');
});

test('BR-PR10 without a cover price there is no discount to show', function (): void {
    $price = app(PriceResolver::class)->resolve(resolverSku('90.00', [[10, '80.00']]), 10);

    expect($price->discountPercent)->toBeNull()
        ->and($price->coverPrice)->toBeNull();
});

test('BR-U05 a quantity below 1 is refused', function (): void {
    app(PriceResolver::class)->resolve(resolverSku(), 0);
})->throws(InvalidArgumentException::class, 'at least 1');

test('the resolver never queries: tiers must be loaded first', function (): void {
    app(PriceResolver::class)->resolve(new SkuUnit(['base_price' => '10.00']), 1);
})->throws(LogicException::class, 'Load the SKU tiers');

test('tiers() lists the stored tiers lowest threshold first', function (): void {
    $sku = resolverSku('100.00', [[50, '80.00', '20.00'], [10, '90.00']]);

    expect(app(PriceResolver::class)->tiers($sku))->toEqual([
        new TierData(10, '90.00'),
        new TierData(50, '80.00', '20.00'),
    ]);
});

/*
| BR-PR14 (V2 Task 11, D89): the customer's PricingContext on top of BR-PR04. The SKU unit below: base 100.00, 10+ → 90.00,
| 100+ → 80.00, cover 125.00, unit id 7 of SKU 7.
*/

function contextSku(): SkuUnit
{
    $sku = resolverSku('100.00', [[10, '90.00'], [100, '80.00']], coverPrice: '125.00');
    $sku->id = 7;
    $sku->sku_id = 7;

    return $sku;
}

test('BR-PR14 D89 price precedence: negotiated price, else the lower of the normal price and the group price', function (?PricingContext $context, int $quantity, string $unitPrice, ?int $tier, PriceSource $source): void {
    $price = app(PriceResolver::class)->resolve(contextSku(), $quantity, $context);

    expect($price->unitPrice)->toBe($unitPrice)
        ->and($price->tierMinQuantity)->toBe($tier)
        ->and($price->source)->toBe($source)
        ->and($price->lineTotal)->toBe(bcmul($unitPrice, (string) $quantity, 2));
})->with([
    'no context is V1, base' => [null, 1, '100.00', null, PriceSource::Base],
    'no context is V1, tier' => [null, 10, '90.00', 10, PriceSource::Tier],
    'an empty context is V1' => [new PricingContext, 100, '80.00', 100, PriceSource::Tier],
    'group lower than base' => [new PricingContext('15.00'), 1, '85.00', null, PriceSource::Group],
    'group lower than the first tier' => [new PricingContext('15.00'), 10, '85.00', null, PriceSource::Group],
    'tier lower than group' => [new PricingContext('15.00'), 100, '80.00', 100, PriceSource::Tier],
    'group equal to the tier keeps the tier' => [new PricingContext('10.00'), 10, '90.00', 10, PriceSource::Tier],
    'a 0% group changes nothing' => [new PricingContext('0.00'), 1, '100.00', null, PriceSource::Base],
    'no group (or an archived one) is V1' => [new PricingContext(null, [8 => '50.00']), 1, '100.00', null, PriceSource::Base],
    'negotiated below everything' => [new PricingContext('15.00', [7 => '70.00']), 100, '70.00', null, PriceSource::Customer],
    'negotiated beats both even when higher' => [new PricingContext('15.00', [7 => '95.00']), 100, '95.00', null, PriceSource::Customer],
    'negotiated above the base price' => [new PricingContext(null, [7 => '110.00']), 1, '110.00', null, PriceSource::Customer],
]);

test('BR-PR12 D27 the group price is the base price less the % rounded half-up to the piaster', function (): void {
    $price = app(PriceResolver::class)->resolve(tap(resolverSku('10.10'), function (SkuUnit $sku): void {
        $sku->id = 1;
        $sku->sku_id = 1;
    }), 1, new PricingContext('25.00'));

    expect($price->unitPrice)->toBe('7.58')
        ->and($price->source)->toBe(PriceSource::Group);
});

test('BR-PR12 a group price never goes below 0.01', function (): void {
    expect(app(PriceResolver::class)->resolve(tap(resolverSku('0.02'), function (SkuUnit $sku): void {
        $sku->id = 1;
        $sku->sku_id = 1;
    }), 1, new PricingContext('90.00'))->unitPrice)->toBe('0.01');
});

test('BR-PR10 BR-PR14 a group or negotiated price shows its discount off the cover price, none above it', function (?string $groupPercent, array $prices, ?string $discount): void {
    expect(app(PriceResolver::class)->resolve(contextSku(), 1, new PricingContext($groupPercent, $prices))->discountPercent)->toBe($discount);
})->with([
    'group' => ['20.00', [], '36.00'],
    'negotiated' => [null, [7 => '75.00'], '40.00'],
    'negotiated above the cover price' => [null, [7 => '130.00'], null],
]);

test('BR-PR14 a negotiated price needs no tiers and carries the cover price', function (): void {
    $price = app(PriceResolver::class)->resolve(contextSku(), 3, new PricingContext(null, [7 => '75.00']));

    expect($price->coverPrice)->toBe('125.00')
        ->and($price->lineTotal)->toBe('225.00');
});

test('BR-PR14 listed() without a context is the sku as stored', function (): void {
    $listed = app(PriceResolver::class)->listed(contextSku());

    expect($listed->basePrice)->toBe('100.00')
        ->and($listed->source)->toBe(PriceSource::Base)
        ->and($listed->discountPercent)->toBe('20.00')
        ->and($listed->tiers)->toEqual([new TierData(10, '90.00'), new TierData(100, '80.00')]);
});

test('BR-PR14 listed() shows a group price with only the tiers below it', function (): void {
    $listed = app(PriceResolver::class)->listed(contextSku(), new PricingContext('15.00'));

    expect($listed->basePrice)->toBe('85.00')
        ->and($listed->source)->toBe(PriceSource::Group)
        ->and($listed->discountPercent)->toBe('32.00')
        ->and($listed->tiers)->toEqual([new TierData(100, '80.00')]);
});

test('BR-PR14 listed() shows a negotiated price alone, at every quantity', function (): void {
    $listed = app(PriceResolver::class)->listed(contextSku(), new PricingContext('15.00', [7 => '95.00']));

    expect($listed->basePrice)->toBe('95.00')
        ->and($listed->source)->toBe(PriceSource::Customer)
        ->and($listed->tiers)->toBe([]);
});

test('BR-PR14 listed() keeps the base price when a 0% group does not lower it', function (): void {
    $listed = app(PriceResolver::class)->listed(contextSku(), new PricingContext('0.00'));

    expect($listed->basePrice)->toBe('100.00')
        ->and($listed->source)->toBe(PriceSource::Base)
        ->and($listed->tiers)->toHaveCount(2);
});

/*
| BR-PR15 (V2 Task 12, D90): a live promotion of the SKU in the context. The same book (base 100.00, 10+ → 90.00,
| 100+ → 80.00, cover 125.00, sku 7).
*/

function promoOff(string $percent, int $id = 3): App\Modules\Pricing\Data\PromotionPrice
{
    return new App\Modules\Pricing\Data\PromotionPrice($id, 'عرض الأضواء', $percent, Carbon\CarbonImmutable::parse('2026-11-30 22:00:00'));
}

test('BR-PR15 D90 price precedence with a promotion: negotiated wins, else the lowest of normal, group and promotion', function (PricingContext $context, int $quantity, string $unitPrice, ?int $tier, PriceSource $source): void {
    $price = app(PriceResolver::class)->resolve(contextSku(), $quantity, $context);

    expect($price->unitPrice)->toBe($unitPrice)
        ->and($price->tierMinQuantity)->toBe($tier)
        ->and($price->source)->toBe($source)
        ->and($price->promotion?->promotionId)->toBe($source === PriceSource::Promotion ? 3 : null);
})->with([
    'promotion lower than base' => [new PricingContext(promotions: [7 => promoOff('15.00')]), 1, '85.00', null, PriceSource::Promotion],
    'promotion lower than the first tier' => [new PricingContext(promotions: [7 => promoOff('15.00')]), 10, '85.00', null, PriceSource::Promotion],
    'tier lower than the promotion' => [new PricingContext(promotions: [7 => promoOff('15.00')]), 100, '80.00', 100, PriceSource::Tier],
    'promotion equal to the tier keeps the tier' => [new PricingContext(promotions: [7 => promoOff('10.00')]), 10, '90.00', 10, PriceSource::Tier],
    'promotion lower than the group' => [new PricingContext('10.00', [], [7 => promoOff('15.00')]), 1, '85.00', null, PriceSource::Promotion],
    'group lower than the promotion' => [new PricingContext('20.00', [], [7 => promoOff('15.00')]), 1, '80.00', null, PriceSource::Group],
    'group equal to the promotion keeps the group' => [new PricingContext('15.00', [], [7 => promoOff('15.00')]), 1, '85.00', null, PriceSource::Group],
    'negotiated above the promotion still wins (D90 a)' => [new PricingContext(null, [7 => '95.00'], [7 => promoOff('50.00')]), 1, '95.00', null, PriceSource::Customer],
    'a promotion of another sku changes nothing' => [new PricingContext(promotions: [8 => promoOff('50.00')]), 1, '100.00', null, PriceSource::Base],
]);

test('BR-PR15 D27 the promotion price is the base price less the % rounded half-up, never below 0.01', function (string $base, string $percent, string $expected): void {
    $sku = resolverSku($base);
    $sku->id = 1;
    $sku->sku_id = 1;

    expect(app(PriceResolver::class)->resolve($sku, 1, new PricingContext(promotions: [1 => promoOff($percent)]))->unitPrice)->toBe($expected);
})->with([
    'half-up' => ['10.10', '25.00', '7.58'],
    'floor at 0.01' => ['0.02', '89.99', '0.01'],
]);

test('BR-PR15 a promotion price shows its discount off the cover price', function (): void {
    expect(app(PriceResolver::class)->resolve(contextSku(), 1, new PricingContext(promotions: [7 => promoOff('20.00')]))->discountPercent)->toBe('36.00');
});

test('BR-PR15 listed() shows a promotion price with its promotion and only the tiers below it', function (): void {
    $listed = app(PriceResolver::class)->listed(contextSku(), new PricingContext(promotions: [7 => promoOff('15.00')]));

    expect($listed->basePrice)->toBe('85.00')
        ->and($listed->source)->toBe(PriceSource::Promotion)
        ->and($listed->promotion?->name)->toBe('عرض الأضواء')
        ->and($listed->tiers)->toEqual([new TierData(100, '80.00')]);
});

test('BR-PR15 listed() keeps the group price and no promotion when the group is lower', function (): void {
    $listed = app(PriceResolver::class)->listed(contextSku(), new PricingContext('20.00', [], [7 => promoOff('15.00')]));

    expect($listed->basePrice)->toBe('80.00')
        ->and($listed->source)->toBe(PriceSource::Group)
        ->and($listed->promotion)->toBeNull();
});

test('BR-PR15 promotionPrice() prices a sku under a % without tiers loaded', function (): void {
    $sku = new SkuUnit(['base_price' => '10.10']);

    expect(app(PriceResolver::class)->promotionPrice($sku, '25.00'))->toBe('7.58');
});

/*
| D91 (V2 Task 13): every unit of a SKU is priced on its own. The carton below is unit 9 of SKU 7 (the piece above is
| unit 7): 12 pieces, base 1100.00, 5+ cartons → 1050.00, cover 1500.00.
*/

function cartonUnit(): SkuUnit
{
    $unit = resolverSku('1100.00', [[5, '1050.00']], coverPrice: '1500.00');
    $unit->id = 9;
    $unit->sku_id = 7;

    return $unit;
}

test('D91 BR-PR04 each unit resolves on its own tiers', function (int $quantity, string $piece, string $carton): void {
    $resolver = app(PriceResolver::class);

    expect($resolver->resolve(contextSku(), $quantity)->unitPrice)->toBe($piece)
        ->and($resolver->resolve(cartonUnit(), $quantity)->unitPrice)->toBe($carton);
})->with([
    'one' => [1, '100.00', '1100.00'],
    'the carton tier, below the piece tier' => [5, '100.00', '1050.00'],
    'the piece tier' => [10, '90.00', '1050.00'],
]);

test('D91 BR-PR12 a group % takes off each unit\'s own base price', function (): void {
    $context = new PricingContext('10.00');

    expect(app(PriceResolver::class)->resolve(contextSku(), 1, $context)->unitPrice)->toBe('90.00')
        ->and(app(PriceResolver::class)->resolve(cartonUnit(), 1, $context)->unitPrice)->toBe('990.00');
});

test('D91 BR-PR15 a promotion on the SKU takes off every unit\'s base price', function (): void {
    $context = new PricingContext(promotions: [7 => promoOff('15.00')]);

    expect(app(PriceResolver::class)->resolve(contextSku(), 1, $context)->unitPrice)->toBe('85.00')
        ->and(app(PriceResolver::class)->resolve(cartonUnit(), 1, $context)->unitPrice)->toBe('935.00')
        ->and(app(PriceResolver::class)->resolve(cartonUnit(), 1, $context)->source)->toBe(PriceSource::Promotion);
});

test('D91 BR-PR13 a price negotiated for one unit leaves the other units at their own prices', function (): void {
    $context = new PricingContext(null, [9 => '1000.00']);

    expect(app(PriceResolver::class)->resolve(cartonUnit(), 1, $context)->unitPrice)->toBe('1000.00')
        ->and(app(PriceResolver::class)->resolve(cartonUnit(), 1, $context)->source)->toBe(PriceSource::Customer)
        ->and(app(PriceResolver::class)->resolve(contextSku(), 1, $context)->unitPrice)->toBe('100.00')
        ->and(app(PriceResolver::class)->resolve(contextSku(), 1, $context)->source)->toBe(PriceSource::Base);
});
