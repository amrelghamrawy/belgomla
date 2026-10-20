<?php

declare(strict_types=1);

use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Exceptions\DiscountTooLarge;
use App\Modules\Pricing\Services\DiscountCalculator;

test('BR-PR08 the unit price after a line discount, a percent rounded half-up to the piaster (D27)', function (string $list, DiscountType $type, string $value, string $unit): void {
    expect(app(DiscountCalculator::class)->unitAfter($list, $type, $value))->toBe($unit);
})->with([
    'amount off' => ['90.00', DiscountType::Amount, '5', '85.00'],
    'amount leaving one piaster' => ['90.00', DiscountType::Amount, '89.99', '0.01'],
    '10% off' => ['90.00', DiscountType::Percent, '10', '81.00'],
    'exactly half a piaster rounds up (7.575)' => ['10.10', DiscountType::Percent, '25', '7.58'],
    'just under half a piaster rounds down (7.5675)' => ['10.09', DiscountType::Percent, '25', '7.57'],
    'a fractional percent' => ['99.99', DiscountType::Percent, '33.33', '66.66'],
]);

test('D85 a line discount never takes the unit price below 0.01', function (DiscountType $type, string $value): void {
    expect(fn (): string => app(DiscountCalculator::class)->unitAfter('90.00', $type, $value))->toThrow(function (DiscountTooLarge $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'discount_too_large', ['list_unit_price' => '90.00']]);
    });
})->with([
    'the whole price as an amount' => [DiscountType::Amount, '90'],
    'more than the price' => [DiscountType::Amount, '120'],
    '100%' => [DiscountType::Percent, '100'],
    'a percent that rounds to zero' => [DiscountType::Percent, '99.995'],
]);

test('D85 a percent off a one-piaster price that still rounds to a piaster is kept', function (): void {
    expect(app(DiscountCalculator::class)->unitAfter('0.01', DiscountType::Percent, '50'))->toBe('0.01');
});

test('BR-O08 the order discount amount, a percent of the items total rounded half-up', function (DiscountType $type, string $value, string $amount): void {
    expect(app(DiscountCalculator::class)->orderAmount('1390.00', $type, $value))->toBe($amount);
})->with([
    'an amount' => [DiscountType::Amount, '50', '50.00'],
    '10%' => [DiscountType::Percent, '10', '139.00'],
    '3.33% (46.287)' => [DiscountType::Percent, '3.33', '46.29'],
    'the most an amount may take' => [DiscountType::Amount, '1389.99', '1389.99'],
]);

test('D27 an order percent of exactly half a piaster rounds up', function (): void {
    expect(app(DiscountCalculator::class)->orderAmount('6.70', DiscountType::Percent, '5'))->toBe('0.34');
});

test('D85 the order discount is capped below the items total: it never leaves less than 0.01', function (DiscountType $type, string $value): void {
    expect(fn (): string => app(DiscountCalculator::class)->orderAmount('1390.00', $type, $value))->toThrow(function (DiscountTooLarge $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'discount_too_large', ['items_total' => '1390.00']]);
    });
})->with([
    'the whole total' => [DiscountType::Amount, '1390'],
    'more than the total' => [DiscountType::Amount, '2000'],
    '100%' => [DiscountType::Percent, '100'],
]);

test('a discount must be above zero', function (string $value): void {
    app(DiscountCalculator::class)->unitAfter('90.00', DiscountType::Amount, $value);
})->with(['zero' => ['0'], 'negative' => ['-5'], 'not a number' => ['abc']])->throws(InvalidArgumentException::class, 'A discount must be above 0');

test('the order discount needs a positive value and a numeric total', function (string $total, string $value): void {
    app(DiscountCalculator::class)->orderAmount($total, DiscountType::Percent, $value);
})->with(['zero value' => ['100.00', '0'], 'not a total' => ['abc', '5']])->throws(InvalidArgumentException::class);

test('D86 an order discount on what was delivered is capped to leave 0.01 instead of refused', function (DiscountType $type, string $value, string $expected): void {
    expect(app(DiscountCalculator::class)->cappedOrderAmount('90.00', $type, $value))->toBe($expected);
})->with([
    'fits' => [DiscountType::Amount, '40', '40.00'],
    'a percent that fits' => [DiscountType::Percent, '10', '9.00'],
    'more than the total' => [DiscountType::Amount, '1000', '89.99'],
]);
