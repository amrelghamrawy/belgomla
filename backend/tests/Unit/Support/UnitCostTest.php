<?php

declare(strict_types=1);

use App\Support\UnitCost;

test('D96 a a price per unit becomes the cost of one base unit, half-up to 4 decimals', function (string $price, int $factor, string $expected): void {
    expect(UnitCost::perBaseUnit($price, $factor))->toBe($expected);
})->with([
    'the base unit itself: 12 ÷ 1' => ['12', 1, '12.0000'],
    'a carton of 50 at 300.00: 300 ÷ 50' => ['300.00', 50, '6.0000'],
    'a third rounds down: 100 ÷ 3 = 33.33333…' => ['100.00', 3, '33.3333'],
    'two thirds round up: 200 ÷ 3 = 66.66666…' => ['200.00', 3, '66.6667'],
    'a piaster over 4 is exact: 0.01 ÷ 4' => ['0.01', 4, '0.0025'],
    'free goods: 0 ÷ 24' => ['0', 24, '0.0000'],
]);

test('D96 a a price with more than two decimals or a factor below 1 is refused', function (string $price, int $factor): void {
    UnitCost::perBaseUnit($price, $factor);
})->with([
    'three decimals' => ['1.005', 1],
    'not a number' => ['abc', 1],
    'factor zero' => ['10.00', 0],
])->throws(InvalidArgumentException::class);

test('BR-INV09 the average weighs what is on hand against what comes in', function (int $onHand, ?string $average, int $received, string $cost, string $expected): void {
    expect(UnitCost::average($onHand, $average, $received, $cost))->toBe($expected);
})->with([
    'the rule\'s example: (100 × 5 + 100 × 6) ÷ 200' => [100, '5.0000', 100, '6.0000', '5.5000'],
    'uneven: (120 × 5 + 48 × 6.25) ÷ 168 = 900 ÷ 168 = 5.357142…' => [120, '5.0000', 48, '6.2500', '5.3571'],
    'half-up on the last place: (1 × 0.0001 + 1 × 0) ÷ 2 = 0.00005' => [1, '0.0001', 1, '0.0000', '0.0001'],
    'no average yet: the cost received is taken' => [40, null, 10, '7.2500', '7.2500'],
    'nothing on hand: the cost received is taken, the old average forgotten' => [0, '9.0000', 5, '4.0000', '4.0000'],
]);

test('BR-INV09 an average needs at least one unit received at a cost that is not negative', function (int $received, string $cost): void {
    UnitCost::average(10, '5.0000', $received, $cost);
})->with([
    'nothing received' => [0, '5.0000'],
    'negative cost' => [5, '-1.0000'],
    'not a number' => [5, 'x'],
])->throws(InvalidArgumentException::class);

test('a stored cost is normalised to 4 decimals and null passes through', function (): void {
    expect(UnitCost::normalizeOrNull('5.5'))->toBe('5.5000')
        ->and(UnitCost::normalizeOrNull(null))->toBeNull();
});
