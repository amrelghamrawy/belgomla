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

test('add sums two amounts exactly', function (): void {
    expect(Money::add('0.10', '0.20'))->toBe('0.30')
        ->and(Money::add('540.00', '1020.5'))->toBe('1560.50');
});

test('add refuses what is not a number', function (): void {
    Money::add('1.00', 'x');
})->throws(InvalidArgumentException::class);

test('times multiplies a unit price by a whole quantity exactly', function (): void {
    expect(Money::times('89.99', 35))->toBe('3149.65')
        ->and(Money::times('0.10', 3))->toBe('0.30');
});

test('compare and times refuse what is not a number', function (): void {
    Money::times('abc', 2);
})->throws(InvalidArgumentException::class, 'is not a money amount');

test('subtract gives the difference at two decimals', function (string $a, string $b, string $difference): void {
    expect(Money::subtract($a, $b))->toBe($difference);
})->with([
    'a remaining balance' => ['500.00', '120.50', '379.50'],
    'nothing left' => ['90', '90.00', '0.00'],
    'below zero' => ['10.00', '10.01', '-0.01'],
]);

test('divide shares an amount over a whole count, half-up to the piaster', function (string $amount, int $count, string $share): void {
    expect(Money::divide($amount, $count))->toBe($share);
})->with([
    'exact' => ['300.00', 2, '150.00'],
    'a third' => ['302.00', 3, '100.67'],
    'half a piaster up' => ['0.05', 2, '0.03'],
    'below zero' => ['-0.05', 2, '-0.03'],
]);

test('divide refuses a count below one', function (): void {
    expect(fn (): string => Money::divide('10.00', 0))->toThrow(InvalidArgumentException::class);
});

test('percentOf gives a part of a whole in percent, half-up, and null for a whole not above zero', function (string $part, string $whole, ?string $percent): void {
    expect(Money::percentOf($part, $whole))->toBe($percent);
})->with([
    'a fifth' => ['1', '5', '20.00'],
    'a third' => ['280.00', '380.00', '73.68'],
    'more than all of it' => ['150.00', '100.00', '150.00'],
    'a zero whole' => ['10.00', '0.00', null],
    'a negative whole' => ['10.00', '-5.00', null],
]);
