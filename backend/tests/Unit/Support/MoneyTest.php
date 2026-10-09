<?php

declare(strict_types=1);

use App\Support\Money;

test('amounts are normalised to two decimals as strings', function (string $input, string $expected): void {
    expect(Money::normalize($input))->toBe($expected);
})->with([
    'integer' => ['90', '90.00'],
    'one decimal' => ['90.5', '90.50'],
    'two decimals' => ['120.25', '120.25'],
    'surrounding spaces' => [' 7 ', '7.00'],
    'zero' => ['0', '0.00'],
    'large' => ['9999999999.99', '9999999999.99'],
]);

test('anything that is not a 2-decimal amount is rejected', function (string $input): void {
    Money::normalize($input);
})->with([
    'three decimals' => ['1.005'],
    'letters' => ['abc'],
    'comma decimal' => ['1,50'],
    'empty' => [''],
    'exponent' => ['1e3'],
])->throws(InvalidArgumentException::class);

test('null passes through normalizeOrNull', function (): void {
    expect(Money::normalizeOrNull(null))->toBeNull()
        ->and(Money::normalizeOrNull('5'))->toBe('5.00');
});
