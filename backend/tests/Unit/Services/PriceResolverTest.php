<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Database\Eloquent\Collection;

/*
| The resolver is pure: every SKU here is built in memory with its tiers set as a loaded relation,
| no database row exists.
*/

/**
 * @param  list<array{0: int, 1: string, 2?: string}>  $tiers  [min_quantity, unit_price, discount_percent?]
 */
function resolverSku(string $basePrice = '100.00', array $tiers = [], ?string $coverPrice = null, ?string $basePricePercent = null, int $moq = 1): Sku
{
    $sku = new Sku(['base_price' => $basePrice, 'cover_price' => $coverPrice, 'base_price_percent' => $basePricePercent, 'moq' => $moq]);

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
    app(PriceResolver::class)->resolve(new Sku(['base_price' => '10.00']), 1);
})->throws(LogicException::class, 'Load the SKU tiers');

test('tiers() lists the stored tiers lowest threshold first', function (): void {
    $sku = resolverSku('100.00', [[50, '80.00', '20.00'], [10, '90.00']]);

    expect(app(PriceResolver::class)->tiers($sku))->toEqual([
        new TierData(10, '90.00'),
        new TierData(50, '80.00', '20.00'),
    ]);
});
