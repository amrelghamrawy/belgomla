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

test('compare orders two amounts at two decimals', function (string $a, string $b, int $result): void {
    expect(Money::compare($a, $b))->toBe($result);
})->with([
    'lower' => ['89.99', '90', -1],
    'equal with different formatting' => ['90', '90.00', 0],
    'higher' => ['90.01', '90.00', 1],
]);

test('times multiplies a unit price by a whole quantity exactly', function (): void {
    expect(Money::times('89.99', 35))->toBe('3149.65')
        ->and(Money::times('0.10', 3))->toBe('0.30');
});

test('compare and times refuse what is not a number', function (): void {
    Money::times('abc', 2);
})->throws(InvalidArgumentException::class, 'is not a money amount');
