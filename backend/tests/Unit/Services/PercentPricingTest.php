<?php

declare(strict_types=1);

use App\Modules\Pricing\Services\PercentPricing;

test('BR-PR10 a price from a percent off the cover price, rounded half-up to the piaster (D27)', function (string $cover, string $percent, string $price): void {
    expect(app(PercentPricing::class)->priceFromPercent($cover, $percent))->toBe($price);
})->with([
    '120.00 at 25%' => ['120.00', '25.00', '90.00'],
    '99.99 at 33.33% (66.663333)' => ['99.99', '33.33', '66.66'],
    'exactly half a piaster rounds up (7.575)' => ['10.10', '25.00', '7.58'],
    'just under half a piaster rounds down (7.5675)' => ['10.09', '25.00', '7.57'],
    'half a piaster on the smallest price (0.005)' => ['0.01', '50.00', '0.01'],
    'unformatted input' => ['120', '25', '90.00'],
]);

test('BR-PR10 a price from a percent refuses a cover price that is not positive', function (string $cover): void {
    app(PercentPricing::class)->priceFromPercent($cover, '25.00');
})->with([
    'zero' => ['0'],
    'negative' => ['-120.00'],
    'not a number' => ['abc'],
])->throws(InvalidArgumentException::class, 'cover price must be a positive amount');

test('BR-PR10 a discount percent must be above 0 and below 100', function (string $percent): void {
    app(PercentPricing::class)->priceFromPercent('120.00', $percent);
})->with([
    'zero' => ['0'],
    'negative' => ['-5'],
    'one hundred' => ['100'],
    'above one hundred' => ['150'],
    'not a number' => ['x'],
])->throws(InvalidArgumentException::class, 'above 0 and below 100');

test('BR-PR10 the discount a price implies off the cover price, rounded half-up', function (string $cover, string $price, ?string $percent): void {
    expect(app(PercentPricing::class)->percentOff($cover, $price))->toBe($percent);
})->with([
    '90 of 120' => ['120.00', '90.00', '25.00'],
    'a third off rounds down (33.333…)' => ['3.00', '2.00', '33.33'],
    'two thirds off rounds up (66.666…)' => ['3.00', '1.00', '66.67'],
    'the cover price itself' => ['120.00', '120.00', '0.00'],
    'above the cover price has no discount' => ['120.00', '130.00', null],
]);

test('the implied discount refuses a price that is not positive', function (): void {
    app(PercentPricing::class)->percentOff('120.00', '0');
})->throws(InvalidArgumentException::class, 'price must be a positive amount');
